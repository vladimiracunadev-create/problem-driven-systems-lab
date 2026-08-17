// Go Lab Dispatcher — un solo contenedor, un solo puerto para los 12 casos.
//
// Espejo del patron java-dispatcher / dotnet-dispatcher / node-dispatcher:
//   - Spawnea cada caso como subproceso interno (/app/cases/0X/case0X).
//   - Escucha publico en :8600.
//   - Enruta por prefijo de path: /01/* → :9601, ..., /12/* → :9612.
//   - Los puertos internos nunca se exponen al host.
//
// Diferencia respecto de los otros dispatchers del lab: aca el proxy inverso no
// se escribe a mano. `net/http/httputil.ReverseProxy` es stdlib y ya resuelve
// streaming, hop-by-hop headers y manejo de errores de upstream. Java y .NET
// copian bytes y cabeceras a mano porque su biblioteca estandar no trae un
// proxy inverso; Node necesita reimplementarlo sobre `http.request`.
package main

import (
	"fmt"
	"io"
	"log"
	"net/http"
	"net/http/httputil"
	"net/url"
	"os"
	"os/exec"
	"strconv"
	"strings"
	"time"
)

type caseInfo struct {
	ID     string
	Port   int
	Name   string
	Binary string
}

var cases = []caseInfo{
	{"01", 9601, "API lenta bajo carga", "/app/cases/01/case01"},
	{"02", 9602, "N+1 y cuellos de botella DB", "/app/cases/02/case02"},
	{"03", 9603, "Observabilidad deficiente", "/app/cases/03/case03"},
	{"04", 9604, "Timeout chain y retry storms", "/app/cases/04/case04"},
	{"05", 9605, "Presion de memoria y fugas", "/app/cases/05/case05"},
	{"06", 9606, "Pipeline roto y delivery fragil", "/app/cases/06/case06"},
	{"07", 9607, "Modernizacion incremental monolito", "/app/cases/07/case07"},
	{"08", 9608, "Extraccion critica de modulo", "/app/cases/08/case08"},
	{"09", 9609, "Integracion externa inestable", "/app/cases/09/case09"},
	{"10", 9610, "Arquitectura cara para algo simple", "/app/cases/10/case10"},
	{"11", 9611, "Reportes que bloquean operacion", "/app/cases/11/case11"},
	{"12", 9612, "Punto unico de conocimiento", "/app/cases/12/case12"},
	{"13", 9613, "Cache stampede y thundering herd", "/app/cases/13/case13"},
	{"14", 9614, "Agotamiento del pool de conexiones", "/app/cases/14/case14"},
	{"15", 9615, "Backpressure en colas de mensajes", "/app/cases/15/case15"},
}

var (
	dispatchPort = envOr("PORT", "8600")
	appStack     = envOr("APP_STACK", "Go 1.23")
	proxies      = map[string]*httputil.ReverseProxy{}
)

func main() {
	log.Printf("[go-dispatcher] starting %d case servers...", len(cases))
	for _, c := range cases {
		spawnCase(c)
		target, err := url.Parse(fmt.Sprintf("http://127.0.0.1:%d", c.Port))
		if err != nil {
			log.Fatalf("url invalida para caso %s: %v", c.ID, err)
		}
		proxy := httputil.NewSingleHostReverseProxy(target)
		proxy.ErrorHandler = func(w http.ResponseWriter, r *http.Request, err error) {
			w.Header().Set("Content-Type", "application/json; charset=utf-8")
			w.WriteHeader(http.StatusBadGateway)
			fmt.Fprintf(w, `{"error":"upstream_unavailable","detail":%q}`, err.Error())
		}
		proxies[c.ID] = proxy
	}

	for _, c := range cases {
		waitHealthy(c, 30*time.Second)
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/", route)

	log.Printf("[go-dispatcher] listening on %s", dispatchPort)
	if err := http.ListenAndServe(":"+dispatchPort, mux); err != nil {
		log.Fatalf("listen: %v", err)
	}
}

func spawnCase(c caseInfo) {
	cmd := exec.Command(c.Binary)
	cmd.Env = append(os.Environ(),
		"PORT="+strconv.Itoa(c.Port),
		"APP_STACK="+appStack,
	)
	// Descartar stdout/stderr para no llenar buffers, igual que los otros
	// dispatchers del lab.
	cmd.Stdout = io.Discard
	cmd.Stderr = io.Discard
	if err := cmd.Start(); err != nil {
		log.Fatalf("no se pudo spawnear caso %s: %v", c.ID, err)
	}
	log.Printf("  case %s → interno :%d (pid %d)", c.ID, c.Port, cmd.Process.Pid)
	// Reap del proceso hijo en su propia goroutine — sin esto quedarian zombis.
	go func() { _ = cmd.Wait() }()
}

func waitHealthy(c caseInfo, timeout time.Duration) {
	client := &http.Client{Timeout: 800 * time.Millisecond}
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		resp, err := client.Get(fmt.Sprintf("http://127.0.0.1:%d/health", c.Port))
		if err == nil {
			resp.Body.Close()
			if resp.StatusCode == http.StatusOK {
				log.Printf("  case %s healthy", c.ID)
				return
			}
		}
		time.Sleep(300 * time.Millisecond)
	}
	log.Printf("[go-dispatcher] case %s no respondio health en %s", c.ID, timeout)
}

func route(w http.ResponseWriter, r *http.Request) {
	path := r.URL.Path

	switch path {
	case "/", "/index", "/index.html":
		sendIndex(w)
		return
	case "/health":
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		fmt.Fprintf(w, `{"status":"ok","stack":%q,"role":"dispatcher"}`, appStack)
		return
	}

	if len(path) < 3 || path[0] != '/' {
		notFound(w, path)
		return
	}
	caseID := path[1:3]
	proxy, ok := proxies[caseID]
	if !ok {
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		w.WriteHeader(http.StatusNotFound)
		fmt.Fprintf(w, `{"error":"case_not_found","case":%q}`, caseID)
		return
	}

	// Reescribir el path quitando el prefijo /0X antes de proxear.
	remainder := path[3:]
	if remainder == "" {
		remainder = "/"
	}
	r.URL.Path = remainder
	proxy.ServeHTTP(w, r)
}

func sendIndex(w http.ResponseWriter) {
	var sb strings.Builder
	sb.WriteString(`{"lab":"Problem-Driven Systems Lab","stack":"`)
	sb.WriteString(appStack)
	sb.WriteString(`","role":"dispatcher","usage":"GET /{caso}/{ruta}  →  e.g. /01/health, /04/quote-resilient","cases":{`)
	for i, c := range cases {
		if i > 0 {
			sb.WriteString(",")
		}
		fmt.Fprintf(&sb, `%q:{"name":%q,"health":"/%s/health","index":"/%s/","internal_port":%d}`,
			c.ID, c.Name, c.ID, c.ID, c.Port)
	}
	sb.WriteString("}}")

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	_, _ = io.WriteString(w, sb.String())
}

func notFound(w http.ResponseWriter, path string) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusNotFound)
	fmt.Fprintf(w, `{"error":"not_found","hint":"usa /01/..., /02/..., ..., /12/...","path":%q}`, path)
}

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
