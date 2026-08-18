// Caso 20 — La dead letter queue olvidada — stack Go 1.23.
//
// Cierra el arco que abrio el caso 15: alli la DLQ nace, como la politica de
// rechazo que salva al productor de bloquearse. Aca se ve que pasa cuando nadie
// vuelve a mirarla.
//
// Silencioso: el consumidor falla, manda el mensaje a la DLQ y sigue. Sin
// clasificar, sin reintentar, sin medir, sin alerta. La cola crece durante meses
// y el pipeline se ve sano: throughput normal, cero errores — porque los errores
// se fueron a otro lado.
//
// Observado: el error se clasifica antes de decidir. Lo transitorio se reintenta
// y casi todo se recupera; lo venenoso va a la DLQ con su clase y una muestra del
// payload; la profundidad y la antiguedad se publican; hay umbral.
//
// La distincion que ordena el caso:
//
//	transitorio  — el mismo mensaje funciona en el proximo intento
//	venenoso     — el mismo mensaje NUNCA va a funcionar
//
//	Reintentar lo venenoso es quemar CPU. Mandar lo transitorio a la DLQ es tirar
//	trabajo que se podia salvar. El consumidor que no distingue hace las dos mal.
//
// # Primitiva Go distintiva
//
// **`errors.Is`, `errors.As` y el envoltorio con `%w`.** La clasificacion en Go
// no viaja por una jerarquia de tipos: viaja por una CADENA de errores que cada
// capa envuelve sin perder lo de abajo.
//
//	return fmt.Errorf("procesando msg-%d: %w", id, ErrTransitorio)
//	...
//	if errors.Is(err, ErrTransitorio) { reintentar() }
//	var pe *ErrorVenenoso
//	if errors.As(err, &pe) { aDLQ(msg, pe.Clase) }
//
// Dos ventajas concretas para este caso:
//
//   - **El contexto se acumula sin borrar la causa.** Cada capa agrega su
//     mensaje con `%w`, y `errors.Is` sigue encontrando el sentinel al fondo.
//     Es exactamente lo que hace falta en un registro de DLQ: saber que fallo y
//     tambien donde.
//   - **`errors.Is` compara por valor, no por tipo.** No se rompe cuando el
//     error cruza un limite de paquete, que es donde el `instanceof` de Node
//     deja de funcionar.
//
// Lo que Go NO da: exhaustividad. Nada obliga a manejar una clase de error
// nueva; agregar `ErrCorrupto` compila perfecto y el `if/else` existente lo
// manda al camino por defecto. Ahi Rust gana con su `match` exhaustivo.
package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"net/http"
	"os"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

var (
	appStack      = envOr("APP_STACK", "Go 1.23")
	caseName      = "20 - La dead letter queue olvidada"
	poisonClasses = []string{"schema_mismatch", "unknown_field", "null_required", "invalid_encoding"}
	start         = time.Now()
)

// ErrTransitorio es un sentinel: el mismo mensaje funciona en el proximo intento.
var ErrTransitorio = errors.New("error transitorio")

// ErrorVenenoso lleva la clase adentro: el mismo mensaje NUNCA va a funcionar.
type ErrorVenenoso struct{ Clase string }

func (e *ErrorVenenoso) Error() string { return "mensaje venenoso: " + e.Clase }

func envOr(k, d string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return d
}

func nowMs() float64 { return float64(time.Since(start).Nanoseconds()) / 1e6 }

type dead struct {
	ID          string  `json:"id"`
	ErrorClass  string  `json:"error_class"`
	Attempts    int     `json:"attempts"`
	FirstSeenMs float64 `json:"-"`
	Sample      *sample `json:"sample,omitempty"`
}

type sample struct {
	Idx     int    `json:"idx"`
	Payload string `json:"payload"`
}

type slot struct {
	Runs         int `json:"runs"`
	Consumed     int `json:"consumed"`
	Succeeded    int `json:"succeeded"`
	Retried      int `json:"retried"`
	DeadLettered int `json:"dead_lettered"`
	AlertsFired  int `json:"alerts_fired"`
}

var (
	mu          sync.Mutex
	dlq         []dead
	alertsFired int
	metrics     = map[string]*slot{"silent": {}, "observed": {}}
)

func round2(v float64) float64 { return math.Round(v*100) / 100 }

// procesar devuelve un error envuelto con %w, para que errors.Is siga
// encontrando la causa al fondo de la cadena.
func procesar(idx, transientPct, poisonPct, attempt int) error {
	if (idx*53)%101 < poisonPct {
		return fmt.Errorf("procesando msg-%d: %w", idx,
			&ErrorVenenoso{Clase: poisonClasses[idx%len(poisonClasses)]})
	}
	if (idx*37)%101 < transientPct && attempt == 0 {
		return fmt.Errorf("procesando msg-%d: timeout del downstream: %w", idx, ErrTransitorio)
	}
	return nil
}

// ---------------------------------------------------------------------------
// Variante silenciosa: cualquier fallo va a la DLQ, y nadie vuelve
// ---------------------------------------------------------------------------

func consumeSilent(messages, transientPct, poisonPct int) map[string]any {
	mu.Lock()
	dlq = nil
	alertsFired = 0
	mu.Unlock()

	consumed, succeeded, deadCount := 0, 0, 0
	t0 := nowMs()

	for i := 0; i < messages; i++ {
		consumed++
		// El bug entero. `err != nil` y nada mas: no mira QUE error es, no
		// reintenta, no guarda por que fallo.
		if err := procesar(i, transientPct, poisonPct, 0); err != nil {
			mu.Lock()
			dlq = append(dlq, dead{ID: fmt.Sprintf("msg-%d", i), ErrorClass: "unclassified",
				Attempts: 1, FirstSeenMs: nowMs()})
			mu.Unlock()
			deadCount++
			continue
		}
		succeeded++
	}

	return map[string]any{"consumed": consumed, "succeeded": succeeded, "retried": 0,
		"dead_lettered": deadCount, "alerts_fired": 0, "sampled": 0, "wall_ms": round2(nowMs() - t0)}
}

// ---------------------------------------------------------------------------
// Variante observada: clasificar, reintentar, medir, alertar
// ---------------------------------------------------------------------------

func consumeObserved(messages, transientPct, poisonPct, maxRetries, alertThreshold, sampleSize int) map[string]any {
	mu.Lock()
	dlq = nil
	alertsFired = 0
	mu.Unlock()

	consumed, succeeded, retried, deadCount, sampled := 0, 0, 0, 0, 0
	t0 := nowMs()

	for i := 0; i < messages; i++ {
		consumed++
		for attempt := 0; attempt <= maxRetries; attempt++ {
			err := procesar(i, transientPct, poisonPct, attempt)
			if err == nil {
				succeeded++
				break
			}

			// errors.As busca en la CADENA: encuentra el *ErrorVenenoso por
			// mas capas de %w que tenga encima.
			var venenoso *ErrorVenenoso
			if errors.As(err, &venenoso) {
				// Reintentarlo es quemar CPU. Va a la DLQ ya mismo, con su
				// clase y —para los primeros— una muestra del payload.
				mu.Lock()
				var s *sample
				if sampled < sampleSize {
					s = &sample{Idx: i, Payload: fmt.Sprintf("{\"id\": %d, \"campo\": \"...\"}", i)}
					sampled++
				}
				dlq = append(dlq, dead{ID: fmt.Sprintf("msg-%d", i), ErrorClass: venenoso.Clase,
					Attempts: attempt + 1, FirstSeenMs: nowMs(), Sample: s})
				mu.Unlock()
				deadCount++
				break
			}

			// errors.Is compara por VALOR contra el sentinel: no se rompe al
			// cruzar limites de paquete, que es donde falla el instanceof.
			if errors.Is(err, ErrTransitorio) {
				retried++
				if attempt == maxRetries {
					mu.Lock()
					dlq = append(dlq, dead{ID: fmt.Sprintf("msg-%d", i), ErrorClass: "transient_exhausted",
						Attempts: attempt + 1, FirstSeenMs: nowMs()})
					mu.Unlock()
					deadCount++
				}
				continue
			}

			// Un error que no supimos clasificar NO va a la DLQ: sube. Que la
			// clasificacion no sea exhaustiva es el punto debil de Go aca —
			// agregar una clase nueva compila perfecto y cae en este camino.
			break
		}
	}

	alerts := 0
	mu.Lock()
	if len(dlq) > alertThreshold {
		alertsFired++
		alerts = 1
	}
	mu.Unlock()

	return map[string]any{"consumed": consumed, "succeeded": succeeded, "retried": retried,
		"dead_lettered": deadCount, "alerts_fired": alerts, "sampled": sampled,
		"wall_ms": round2(nowMs() - t0)}
}

// ---------------------------------------------------------------------------
// La DLQ como cola observable, no como agujero
// ---------------------------------------------------------------------------

func dlqStats(alertThreshold int) map[string]any {
	mu.Lock()
	defer mu.Unlock()

	porClase := map[string]int{}
	for _, m := range dlq {
		porClase[m.ErrorClass]++
	}
	claves := make([]string, 0, len(porClase))
	for k := range porClase {
		claves = append(claves, k)
	}
	sort.Strings(claves)
	ordenado := map[string]int{}
	for _, k := range claves {
		ordenado[k] = porClase[k]
	}

	oldest := 0.0
	now := nowMs()
	for _, m := range dlq {
		if age := now - m.FirstSeenMs; age > oldest {
			oldest = age
		}
	}
	muestras := []*sample{}
	for _, m := range dlq {
		if m.Sample != nil && len(muestras) < 5 {
			muestras = append(muestras, m.Sample)
		}
	}

	return map[string]any{
		"dlq_depth":             len(dlq),
		"dlq_oldest_msg_age_ms": round2(oldest),
		"by_error_class":        ordenado,
		"alert_threshold":       alertThreshold,
		"over_threshold":        len(dlq) > alertThreshold,
		"alerts_fired":          alertsFired,
		"samples":               muestras,
		"note": "Una DLQ sin profundidad publicada, sin antiguedad del mensaje mas viejo y sin desglose por clase " +
			"de error no es una cola: es un agujero. by_error_class convierte 'hay 4.000 mensajes' en 'hay un bug " +
			"de schema y tres timeouts'.",
	}
}

// dlqDrain hace el replay. Lo que se recupera vuelve; lo venenoso sigue ahi.
// Es la mitad que casi nunca se construye: una DLQ que solo recibe es un
// cementerio; una de la que se puede volver es un buffer.
func dlqDrain(limit, transientPct, poisonPct, maxRetries int) map[string]any {
	t0 := nowMs()
	mu.Lock()
	n := limit
	if n > len(dlq) {
		n = len(dlq)
	}
	lote := append([]dead(nil), dlq[:n]...)
	resto := append([]dead(nil), dlq[n:]...)
	mu.Unlock()

	ok, fallo := 0, 0
	var quedan []dead
	for _, m := range lote {
		idx, _ := strconv.Atoi(strings.TrimPrefix(m.ID, "msg-"))
		recuperado := false
		for attempt := 1; attempt <= maxRetries; attempt++ {
			err := procesar(idx, transientPct, poisonPct, attempt)
			if err == nil {
				recuperado = true
				break
			}
			var venenoso *ErrorVenenoso
			if errors.As(err, &venenoso) {
				break
			}
		}
		if recuperado {
			ok++
		} else {
			fallo++
			m.Attempts += maxRetries
			quedan = append(quedan, m)
		}
	}

	mu.Lock()
	dlq = append(quedan, resto...)
	depth := len(dlq)
	mu.Unlock()

	return map[string]any{
		"drain_limit":       limit,
		"drained_ok":        ok,
		"drain_failed":      fallo,
		"recovered_pct":     round2(float64(ok) * 100 / math.Max(1, float64(ok+fallo))),
		"drain_duration_ms": round2(nowMs() - t0),
		"dlq_depth_after":   depth,
		"note": "Lo que se recupera en el replay es exactamente lo que nunca deberia haber estado aca: errores " +
			"transitorios que un reintento habria resuelto. Lo que sigue fallando es veneno de verdad, y necesita " +
			"un cambio de codigo o de datos — no otro reintento.",
	}
}

func runScenario(variant string, messages, transientPct, poisonPct, maxRetries, alertThreshold, sampleSize int) map[string]any {
	var r map[string]any
	if variant == "silent" {
		r = consumeSilent(messages, transientPct, poisonPct)
	} else {
		r = consumeObserved(messages, transientPct, poisonPct, maxRetries, alertThreshold, sampleSize)
	}
	stats := dlqStats(alertThreshold)

	mu.Lock()
	s := metrics[variant]
	s.Runs++
	s.Consumed += r["consumed"].(int)
	s.Succeeded += r["succeeded"].(int)
	s.Retried += r["retried"].(int)
	s.DeadLettered += r["dead_lettered"].(int)
	s.AlertsFired += r["alerts_fired"].(int)
	mu.Unlock()

	mr := 0
	if variant == "observed" {
		mr = maxRetries
	}
	out := map[string]any{"variant": variant, "messages": messages, "transient_pct": transientPct,
		"poison_pct": poisonPct, "max_retries": mr}
	for k, v := range r {
		out[k] = v
	}
	for _, k := range []string{"dlq_depth", "dlq_oldest_msg_age_ms", "by_error_class", "alert_threshold", "over_threshold"} {
		out[k] = stats[k]
	}
	out["dead_letter_rate_pct"] = round2(float64(r["dead_lettered"].(int)) * 100 / math.Max(1, float64(r["consumed"].(int))))
	if variant == "silent" {
		out["note"] = "El consumidor no clasifico nada: transitorio y venenoso fueron al mismo lugar, sin " +
			"reintentar y sin registrar por que. El pipeline se ve sano —throughput normal, cero errores— porque " +
			"los errores se fueron a otro lado. Y nadie va a volver."
	} else {
		out["note"] = "Lo transitorio se reintento y casi todo se recupero; solo el veneno llego a la DLQ, con su " +
			"clase de error y una muestra del payload. La profundidad esta publicada y el umbral disparo alerta."
	}
	out["go_note"] = "errors.Is y errors.As clasifican por una CADENA de errores envueltos con %w, no por una " +
		"jerarquia de tipos: el contexto se acumula sin borrar la causa, y no se rompe al cruzar limites de " +
		"paquete. Lo que Go no da es exhaustividad: agregar una clase de error nueva compila perfecto."
	return out
}

func diagnostics(alertThreshold int) map[string]any {
	mu.Lock()
	snapshot := map[string]slot{"silent": *metrics["silent"], "observed": *metrics["observed"]}
	mu.Unlock()
	return map[string]any{
		"stack": appStack, "case": caseName, "variants": snapshot, "dlq": dlqStats(alertThreshold),
		"arco_con_el_caso_15": "En el caso 15 la DLQ NACE: es la politica de rechazo que salva al productor de " +
			"bloquearse cuando la cola se llena. Aca se ve que pasa cuando nadie vuelve a mirarla.",
		"fidelity": map[string]any{
			"real": "La clasificacion de errores, el reintento con presupuesto acotado, el desglose por clase, el " +
				"muestreo de payloads y el replay desde la DLQ son codigo de verdad.",
			"modelado": "La DLQ es un slice en memoria, no SQS ni RabbitMQ. La clase de error de cada mensaje es " +
				"deterministica para que el escenario sea reproducible.",
			"honesto": "Lo que define el caso no es el broker: es que un mensaje que falla tiene que ir a algun " +
				"lado, y que ese lado necesita profundidad, antiguedad, clasificacion y una salida.",
		},
		"interpretation": map[string]any{
			"silent": "dead_letter_rate_pct alto, by_error_class con una sola entrada ('unclassified') y " +
				"alerts_fired en cero. El pipeline se ve sano.",
			"observed": "dead_letter_rate_pct bajo —solo el veneno—, by_error_class desglosado y la alerta disparada.",
			"go_note": "La cadena de %w es la mejor forma del set para conservar contexto sin perder la causa. La " +
				"falta de exhaustividad es lo que separa a Go de Rust en este caso.",
		},
	}
}

func clampInt(v, lo, hi int) int {
	if v < lo {
		return lo
	}
	if v > hi {
		return hi
	}
	return v
}

func queryInt(r *http.Request, key string, def int) int {
	raw := r.URL.Query().Get(key)
	if raw == "" {
		return def
	}
	n, err := strconv.Atoi(raw)
	if err != nil {
		return def
	}
	return n
}

func writeJSON(w http.ResponseWriter, status int, payload map[string]any) {
	payload["timestamp_utc"] = time.Now().UTC().Format("2006-01-02T15:04:05Z")
	payload["pid"] = os.Getpid()
	body, _ := json.MarshalIndent(payload, "", "  ")
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	w.Write(body)
}

func main() {
	mux := http.NewServeMux()
	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		uri := r.URL.Path
		messages := clampInt(queryInt(r, "messages", 3000), 10, 200000)
		transientPct := clampInt(queryInt(r, "transient_pct", 12), 0, 100)
		poisonPct := clampInt(queryInt(r, "poison_pct", 4), 0, 100)
		maxRetries := clampInt(queryInt(r, "max_retries", 3), 0, 20)
		alertThreshold := clampInt(queryInt(r, "alert_threshold", 50), 0, 100000)
		sampleSize := clampInt(queryInt(r, "sample_size", 20), 0, 1000)
		limit := clampInt(queryInt(r, "limit", 500), 1, 200000)

		status := 200
		var payload map[string]any

		switch uri {
		case "/", "/index":
			payload = map[string]any{
				"lab": "Problem-Driven Systems Lab", "case": caseName, "stack": appStack,
				"goal": "Mostrar que un pipeline con throughput normal y cero errores puede estar perdiendo el 16% " +
					"de los mensajes, porque los errores se fueron a un lugar que nadie mira.",
				"arco": "Cierra el arco del caso 15, donde la DLQ nace como politica de rechazo.",
				"go_specific": "errors.Is y errors.As clasifican por cadena de errores envueltos con %w; lo que " +
					"falta es exhaustividad.",
				"routes": map[string]string{
					"/health":                          "Estado basico del servicio.",
					"/consume-silent?messages=3000":     "Cualquier fallo a la DLQ, sin clasificar ni reintentar.",
					"/consume-observed?messages=3000":   "Clasificar, reintentar lo transitorio, alertar.",
					"/dlq/stats":                       "Profundidad, antiguedad del mas viejo y desglose por clase.",
					"/dlq/drain?limit=500":             "Replay desde la DLQ: que se recupera y que sigue siendo veneno.",
					"/diagnostics/summary":             "Comparativa entre variantes.",
					"/reset-lab":                       "Vacia la DLQ y las metricas.",
				},
			}
		case "/health":
			payload = map[string]any{"status": "ok", "stack": appStack, "case": caseName}
		case "/consume-silent":
			payload = runScenario("silent", messages, transientPct, poisonPct, maxRetries, alertThreshold, sampleSize)
		case "/consume-observed":
			payload = runScenario("observed", messages, transientPct, poisonPct, maxRetries, alertThreshold, sampleSize)
		case "/dlq/stats":
			payload = dlqStats(alertThreshold)
		case "/dlq/drain":
			payload = dlqDrain(limit, transientPct, poisonPct, maxRetries)
		case "/diagnostics/summary":
			payload = diagnostics(alertThreshold)
		case "/reset-lab":
			mu.Lock()
			dlq = nil
			alertsFired = 0
			metrics = map[string]*slot{"silent": {}, "observed": {}}
			mu.Unlock()
			payload = map[string]any{"status": "reset", "message": "DLQ y metricas reiniciadas."}
		default:
			status = 404
			payload = map[string]any{"error": "Ruta no encontrada", "path": uri}
		}
		writeJSON(w, status, payload)
	})

	port := envOr("PORT", "8080")
	fmt.Printf("Servidor Go escuchando en %s\n", port)
	if err := http.ListenAndServe(":"+port, mux); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}
