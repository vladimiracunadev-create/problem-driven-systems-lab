# ☁️ Migración a AWS

[![AWS](https://img.shields.io/badge/Cloud-AWS-FF9900?logo=amazonaws&logoColor=white)](https://aws.amazon.com/)
[![Multi-Region](https://img.shields.io/badge/Multi--Region-capable-blue)](https://aws.amazon.com/about-aws/global-infrastructure/)
[![Cost-aware](https://img.shields.io/badge/Cost--aware-yes-success)](#-comparación-rápida-de-rutas)
[![Status](https://img.shields.io/badge/Estado-Plan-blue)](#)

> Plan operativo y honesto para mover el laboratorio (7 stacks operativos · 17 casos cada uno · 119 endpoints) desde Docker Compose local hacia AWS. Tres rutas alternativas, costos con rango realista, diagramas navegables y un mapping explícito de **cómo AWS cierra cada hallazgo del [SECURITY.md](SECURITY.md) sin tocar código del lab**.

---

## TL;DR

- **3 rutas:** ECS Fargate (default), Lambda (cargas spiky), EKS (org con K8s ya operado).
- **Costo mensual estimado:** USD 35 (Lambda spiky) — USD 180 (ECS prod-grade con WAF + Cognito) — USD 420 (EKS standalone con HA).
- Cierra los 4 hallazgos abiertos del SECURITY.md con servicios managed: **auth (Cognito), rate limit (WAF), atomicidad (DynamoDB tx), TLS (ACM)**.
- Mantiene el patrón "1 hub por lenguaje" usando ALB con path routing → un target group por stack. La asimetría documentada en el repo (PHP con DB real para casos 01-02, los demás con SQLite embebido para caso 02 y memoria/timer para caso 01) **se preserva** — no se inventa fidelidad que no existe en main.
- Estado real del repo a la fecha de este plan: **7 stacks operativos** (PHP, Python, Node, Java, .NET, Go, Rust), **119 endpoints** detrás de 7 hubs simétricos (`:8100`, `:8200`, `:8300`, `:8400`, `:8500`, `:8600`, `:8700`), portal en `:8080` y observabilidad PHP-only en `:9091` / `:3001`.

---

## 📊 Comparación rápida de rutas

| Aspecto | ECS Fargate | Lambda | EKS |
|---|---|---|---|
| Ideal para | Default — tráfico continuo bajo-medio | Cargas spiky, demo/portfolio | Org con K8s ya operado |
| Costo mensual estimado | USD 80 – 180 | USD 5 – 35 | USD 300 – 420 |
| Costo idle real | NAT + ALB ≈ USD 50 piso | ~USD 0 si no hay tráfico | USD 73 control plane + nodos |
| Cold start | 0 (siempre warm) | 200 ms – 2 s según runtime | 0 |
| Estado | ALB → Service tasks | API Gateway → λ | Ingress → Pods |
| Workers long-running | Nativo | Forzado (EventBridge) | Nativo |
| Curva de aprendizaje | Media | Baja | Alta |
| Vendor lock-in | Bajo | Alto | Bajísimo |
| Valor narrativo del repo | Alto | Alto | Muy alto si el target es DevOps |

**Default recomendado:** ECS Fargate. Es el que mejor preserva el modelo del lab (1 hub por lenguaje = 1 service por lenguaje) sin penalizaciones de cold start y con factura predecible.

---

## 🧭 Inventario actual a migrar

Mapa de lo que vive hoy en los 5 composes raíz:

| Servicio actual | Imagen / runtime | Equivalente AWS sugerido |
|---|---|---|
| `portal-php8` (`:8080`) | PHP 8 + Apache | ECS Fargate Service · o S3 + CloudFront si se hace estático |
| `pdsl-php-lab` (`:8100`) — dispatcher PHP con 12 procesos `php -S` internos | PHP 8.3 + tini | ECS Fargate Service · Lambda Container |
| `pdsl-python-lab` (`:8200`) — dispatcher con 12 subprocesos `subprocess.Popen` | Python 3.12 | ECS Fargate Service · Lambda zip |
| `pdsl-node-lab` (`:8300`) — dispatcher con 12 subprocesos `child_process.spawn` | Node.js 20 | ECS Fargate Service · Lambda zip |
| `pdsl-java-lab` (`:8400`) — dispatcher con 12 `ProcessBuilder` (`java Main`) | Java 21 (eclipse-temurin) | ECS Fargate Service · Lambda Container |
| `pdsl-dotnet-lab` (`:8500`) — dispatcher con 12 subprocesos `dotnet` | .NET 8 | ECS Fargate Service · Lambda Container |
| `case01-db`, `case02-db` | postgres:16-alpine | **RDS PostgreSQL** (db.t4g.micro) · Aurora Serverless v2 |
| `case01-worker` | PHP CLI loop | ECS Fargate Service long-running · EventBridge + Lambda |
| `case01-prometheus` (`:9091`) | prom/prometheus | **AMP** (Amazon Managed Prometheus) |
| `case01-grafana` (`:3001`) | grafana 11 | **AMG** (Amazon Managed Grafana) |
| `case01-postgres-exporter` | postgres-exporter | RDS Performance Insights |

**Volúmenes con estado** (`pgdata_case01`, `pgdata_case02`, `prometheus_case01`, `grafana_case01`) desaparecen como volúmenes EBS/EFS y se reemplazan por managed (RDS / AMP / AMG).

**Nota honesta sobre casos 01 y 02:** los stacks Python/Node/Java/.NET/Go/Rust usan SQLite embebido **dentro del proceso** (sin RDS). Para esos 6 stacks, **no es necesario aprovisionar RDS para esos casos** — el archivo SQLite vive en el filesystem efímero de la task Fargate, lo cual es aceptable porque el lab no persiste estado del caso 02 entre boots. Solo el caso 02 PHP requiere RDS real. Esto baja el costo y simplifica la migración.

---

## 🏗️ Ruta 1 — ECS Fargate (recomendada)

### Topología

```mermaid
graph LR
    Internet([Internet]) --> R53[Route 53]
    R53 --> CF[CloudFront + WAF]
    CF --> ALB[Application Load Balancer]

    ALB -->|/| TG_PORTAL[TG portal]
    ALB -->|/php/*| TG_PHP[TG php-lab]
    ALB -->|/py/*| TG_PY[TG python-lab]
    ALB -->|/node/*| TG_NODE[TG node-lab]
    ALB -->|/java/*| TG_JAVA[TG java-lab]
    ALB -->|/dotnet/*| TG_NET[TG dotnet-lab]

    TG_PORTAL --> S_PORTAL[Fargate: portal-php]
    TG_PHP --> S_PHP[Fargate: php-lab]
    TG_PY --> S_PY[Fargate: python-lab]
    TG_NODE --> S_NODE[Fargate: node-lab]
    TG_JAVA --> S_JAVA[Fargate: java-lab]
    TG_NET --> S_NET[Fargate: dotnet-lab]

    S_PHP --> RDS1[(RDS pg-01)]
    S_PHP --> RDS2[(RDS pg-02)]
    S_PHP --> WORKER[Fargate: case01-worker]

    S_PHP -.metrics.-> AMP[(AMP)]
    AMP --> AMG[AMG dashboards]
```

### Por qué este default

- **Sin servidor que administrar.** Sin EC2, sin Auto Scaling Groups, sin AMIs.
- **Path-based routing en el ALB respeta el patrón actual del lab** (`/php/*`, `/python/*`, `/node/*`, `/java/*`, `/dotnet/*` se mapean 1:1 con los hubs `:8100`, `:8200`, `:8300`, `:8400`, `:8500`).
- **Cost-effective hasta ~10 req/s sostenido.** Por encima de eso conviene comparar con EKS.
- **El dispatcher con subprocesos internos sigue funcionando igual.** El task Fargate corre el mismo container que ya corre local. Cero cambio de código.
- **Workers long-running cuadran nativamente** — el `case01-worker` es un service Fargate más.

### Paso a paso

1. **ECR** — 6 repos: `pdsl/portal`, `pdsl/php-lab`, `pdsl/python-lab`, `pdsl/node-lab`, `pdsl/java-lab`, `pdsl/dotnet-lab`. Build con `docker buildx --platform linux/arm64` para t4g.
2. **VPC** — 2 AZ, subnets pub/priv, 1 NAT Gateway (o VPC endpoints para S3/ECR si querés evitar el costo de NAT).
3. **RDS PostgreSQL × 2** (db.t4g.micro, 20 GB gp3, single-AZ, backups 7 días) para casos 01 y 02 del stack PHP. Credenciales en **Secrets Manager**.
4. **Task Definitions × 6** — una por stack más una por worker. Mismo container que ya corre local.
5. **ECS Services × 6** con `desired_count=1` y `HealthCheckGracePeriodSeconds=30`.
6. **ALB** con un listener HTTPS (443) + 6 listener rules por path. Target group por service con healthcheck a `/health` o `/01/health`.
7. **CloudFront** delante del ALB con cache "CachingDisabled" para `/*/0X/*` (siempre dinámico) y "CachingOptimized" para `/static/*` del portal.
8. **AWS WAF** asociado al CloudFront: `AWSManagedRulesCommonRuleSet` + rate-based rule (2000 req/5min/IP global, 50 req/5min para `/node/11/*` específicamente — ver mapping SECURITY abajo).
9. **Cognito User Pool** + ALB listener-rule action `authenticate-cognito` para rutas que no querés exponer al público anónimo.
10. **AMP workspace** + ADOT collector como sidecar de los hubs para scrapear `/metrics-prometheus` del stack PHP (los demás stacks aún no exportan Prometheus — ver ROADMAP Eje 2).
11. **AMG workspace** con SSO IAM Identity Center.

### Costos detallados (us-east-1, May 2026)

| Componente | Configuración | USD/mes |
|---|---|---|
| 6 × Fargate task (0.25 vCPU, 0.5 GB ARM) × 730h | uno por stack + portal | ~24 |
| Worker case01 Fargate (0.25 vCPU, 0.5 GB) | long-running | ~4 |
| ALB | tráfico bajo | ~18 |
| NAT Gateway | single AZ | ~33 |
| RDS db.t4g.micro × 2 | casos 01 y 02 PHP | ~26 |
| CloudFront | <50 GB egress | ~2 |
| Route 53 | 1 hosted zone | ~0.5 |
| ACM | TLS cert | 0 |
| CloudWatch Logs | 5 GB ingest + 10 GB store | ~4 |
| AMP | <10M samples | ~2 |
| AMG | 1 editor user | ~9 |
| ECR | 5 GB | ~0.5 |
| Secrets Manager | 4 secrets | ~1.6 |
| **Base (sin defensas)** | | **~125** |
| WAF managed + rate-based | mitiga A2 y M3 | +6 |
| Cognito | <50K MAU | +0 (tier gratuito) |
| **Total con defensas** | | **~135 – 180** |

> El rango USD 80 – 180 cubre dos modos: **modo apagado** (EventBridge detiene services fuera de horario laboral → USD ~80) vs **24x7 prod-grade con WAF + Cognito + CloudFront** (USD ~180).

---

## ⚡ Ruta 2 — Lambda + API Gateway

### Topología

```mermaid
graph LR
    Internet([Internet]) --> CF[CloudFront + WAF]
    CF --> APIGW[API Gateway HTTP API]

    APIGW -->|/php/0X| LPHP[Lambda php cases 01-12]
    APIGW -->|/py/0X| LPY[Lambda python cases 01-12]
    APIGW -->|/node/0X| LNODE[Lambda node cases 01-12]
    APIGW -->|/java/0X| LJAVA[Lambda java cases 01-12]
    APIGW -->|/dotnet/0X| LNET[Lambda dotnet cases 01-12]

    LPHP --> AURORA[(Aurora Serverless v2)]
    LPY --> DDB[(DynamoDB state)]
    LNODE --> DDB
    LJAVA --> DDB
    LNET --> DDB

    APIGW -.logs.-> CW[CloudWatch]
```

### Diferencias clave vs ECS

- **Cada caso 0X se vuelve una función Lambda independiente.** 119 funciones totales (17 × 7 stacks). Go y Rust son los dos runtimes con mejor cold start del set — binarios estáticos sin JIT ni intérprete que inicializar, lo que los vuelve los candidatos naturales para esta ruta. El dispatcher desaparece — API Gateway hace el routing.
- **Cold start es significativo para Java y .NET** (300 – 2000 ms primer hit sin SnapStart / ReadyToRun); bajo para Node, Python y PHP (50 – 800 ms).
- **Empacado:** Node/Python/PHP (con custom runtime) usan zip; Java/.NET usan container image.
- **Costo mínimo real:** USD 5/mes para tráfico de demo. Si nadie visita el portfolio, casi USD 0.
- **Aurora Serverless v2** con `min_capacity=0.5` ACU reemplaza las 2 RDS del caso 01-02 PHP. Auto-pause baja a 0 ACU si no hay queries por X minutos.
- **DynamoDB** reemplaza el state JSON en `/tmp` de Python/Node/Java/.NET/Go/Rust para casos que mutan state — y resuelve el hallazgo M4 (atomicidad) automáticamente con `ConditionExpression`.

### Trade-off honesto

Si tu carga es **spiky** (visitas de recruiters esporádicas con picos ocasionales de demo en vivo), Lambda es imbatible. Si es **continua** (>5 req/s todo el día), ECS Fargate sale más barato y sin cold starts. Para un portfolio público que pasa el 95% del tiempo sin visitas, Lambda es la opción frugal.

### Costos estimados

| Componente | Asunción | USD/mes |
|---|---|---|
| Lambda (60 funciones) | <10 000 invocaciones/mes total | ~0 – 1 |
| API Gateway HTTP API | <1M requests/mes | ~1 |
| Aurora Serverless v2 | min 0.5 ACU, ~30 min/día activo | ~25 – 40 |
| DynamoDB | PAY_PER_REQUEST, <1M writes | ~1 – 5 |
| CloudFront + Route 53 + WAF | igual que ECS | ~11 |
| CloudWatch | base | ~2 |
| Cognito | tier gratuito | 0 |
| **Total** | | **~35 – 55** |

### Paso a paso (resumen)

1. ECR con 5 container images (uno por stack para Java/.NET, zip para Node/Python/PHP).
2. Aurora Serverless v2 con `min_capacity=0.5`, `max_capacity=2`.
3. DynamoDB table `pdsl-state` con PK `case_id#scenario` para reemplazar `/tmp/state.json`.
4. 60 funciones Lambda con `MemorySize=512`, `Timeout=30s`. Provisioned concurrency = 0 (asumir cold start aceptable para demo).
5. API Gateway HTTP API con 60 routes.
6. CloudFront delante con WAF y rate limiting.
7. Cognito JWT authorizer en API Gateway para rutas mutantes (`/reset-lab`, `/share-knowledge`).

---

## 🚢 Ruta 3 — EKS

### Topología

```mermaid
graph LR
    Internet([Internet]) --> ALB[ALB Ingress Controller]
    ALB --> EKS[EKS Cluster 2 AZ]

    EKS --> POD_PHP[Deployment php-lab]
    EKS --> POD_PY[Deployment python-lab]
    EKS --> POD_NODE[Deployment node-lab]
    EKS --> POD_JAVA[Deployment java-lab]
    EKS --> POD_NET[Deployment dotnet-lab]
    EKS --> POD_PORTAL[Deployment portal]

    POD_PHP --> RDS[(RDS PostgreSQL)]
    POD_PHP -.metrics.-> AMP[(AMP)]
    AMP --> AMG[AMG dashboards]

    EKS -.scales.-> KARP[Karpenter]
```

### Cuándo elegirlo

**Solo si tu org ya opera Kubernetes.** No es buena idea adoptar EKS solo para este lab. El control plane fijo (USD 73/mes) + nodos (2 × t4g.medium ≈ USD 50/mes) hacen que el piso de costo arranque en USD 123/mes antes de pegarle el primer request.

### Cuándo sí tiene sentido

- El target audience es **DevOps / Platform Engineering** y querés señalizar capacidad K8s (CKA, Karpenter, HPA, PDB).
- Ya tenés un cluster EKS y este lab es un namespace más.
- Querés demostrar deploys progresivos con Argo Rollouts o Flux.

### Costos estimados

| Componente | USD/mes |
|---|---|
| EKS control plane | 73 |
| 2 × t4g.medium (HA) | ~50 |
| RDS × 2, ALB, NAT | ~80 |
| AMP, AMG | ~11 |
| CloudFront + WAF | ~10 |
| **Total** | **~225 – 420** |

El rango superior asume cluster productivo con multi-AZ, observabilidad completa y reserva de capacidad para picos.

---

## 🔐 Mapping SECURITY → defensas AWS

El `SECURITY.md` documenta 4 hallazgos abiertos por diseño (es un lab localhost-only). **Migrar a AWS los cierra sin tocar código del lab**, delegando defensas en servicios managed.

### Diagrama de cobertura

```mermaid
graph LR
    A1[A1: Sin auth] --> COG[Cognito User Pool]
    A1 --> ALBOIDC[ALB OIDC integration]

    A2[A2: DoS event loop caso 11 Node] --> WAF1[WAF rate-based rule]
    A2 --> ASG[ECS Auto Scaling]
    A2 --> HC[ALB health check rotation]

    M1[M1: Mutación acepta cualquier verbo] --> WAF2[WAF custom rule: BLOCK if method != POST]
    M1 --> APIGW[API Gateway method validation]

    M2[M2: Reflejo header Host] --> CFOR[CloudFront origin request policy]
    M2 --> WAF3[WAF managed Host header rules]

    M3[M3: Sin rate limiting] --> WAFRATE[WAF rate-based rules por path]
    M3 --> CFCACHE[CloudFront cache absorbe lecturas]

    M4[M4: Sin atomicidad state JSON] --> DDB[DynamoDB ConditionExpression]
    M4 --> RDSTX[RDS SERIALIZABLE]

    TLS[Sin TLS] --> ACM[ACM cert + ALB HTTPS]
```

### Detalle por hallazgo

| Hallazgo SECURITY.md | Mitigación AWS | Servicio | Costo aprox |
|---|---|---|---|
| **A1** Sin autenticación | ALB OIDC integration con Cognito · o JWT en API Gateway · o WAF custom rule con header `X-API-Key` contra Secrets Manager | Cognito User Pool | USD 0 hasta 50K MAU |
| **A2** DoS del event loop caso 11 Node | WAF rate-based rule (50 req/5min/IP en `/node/11/*`) + ALB health check + ECS Auto Scaling | AWS WAF | USD 6/mes + USD 1 por regla custom |
| **M1** Mutaciones aceptan cualquier verbo | WAF custom rule `if path matches /reset-lab and method != POST then BLOCK` · o API Gateway que valida método en spec | AWS WAF | Incluido en WAF base |
| **M2** Reflejo de header Host en probe.php | CloudFront origin request policy con allowlist de headers + `AWSManagedRulesCommonRuleSet` | CloudFront + WAF | Incluido |
| **M3** Sin rate limiting global | WAF rate-based rules por path: 1000 req/5min para lecturas, 60 req/5min para mutaciones | AWS WAF | Incluido en WAF |
| **M4** Sin atomicidad en escrituras de state | DynamoDB transactions con `ConditionExpression` · o RDS con `SERIALIZABLE` · o S3 con `If-Match` ETag | DynamoDB | USD 0.25 / M writes |
| Sin TLS (observación) | ACM cert (gratuito) + ALB listener HTTPS con política `ELBSecurityPolicy-TLS13-1-2-2021-06` | ACM + ALB | Incluido en ALB |

### Ejemplo concreto: `/node/11/report-legacy?rows=5000000` post-migración

Sin AWS, este endpoint bloquea el event loop del hub Node `:8300` durante ~9s por cada 10 requests concurrentes (hallazgo A2).

Después de migrar:

1. **Cognito** rechaza el request si no hay JWT válido (A1 mitigado).
2. **WAF rate-based rule** (`/node/11/*` limit 50 req/5min/IP) bloquea el flood en el edge antes de tocar el ALB (A2 mitigado).
3. Si por alguna razón llega al backend, **ALB health check** detecta latencia anómala y rota la task antes de que afecte a otras requests (`HealthCheckGracePeriodSeconds=30`).
4. **CloudWatch alarm** sobre `event_loop_lag_p99` notifica via SNS → email/Slack/PagerDuty en 60 segundos.
5. **Auto Scaling** lanza una task adicional si el CPU sostenido supera 60%.

Costo total de las mitigaciones: **~USD 6 – 10/mes** (WAF + Cognito gratuito + Container Insights).

### Defensas adicionales que AWS aporta (que el lab no tiene)

| Capa | Servicio | Qué protege |
|---|---|---|
| Edge | CloudFront + AWS Shield Standard | TLS, cache (≈90% de lecturas absorbidas), DDoS L3/L4 gratis |
| Edge | AWS WAF | OWASP Top 10, bots, geo-blocking, IP reputation |
| Identity | Cognito + IAM Identity Center | Auth de usuarios finales y operadores con SSO |
| Network | VPC privadas + Security Groups | Tasks sin IP pública; ALB único ingress |
| Network | VPC Endpoints | Tasks acceden a S3/ECR sin pasar por NAT |
| App | IAM task roles | Least privilege por service |
| Secrets | Secrets Manager + KMS | Rotación de credenciales DB, cifrado en reposo |
| Detection | GuardDuty | ML threat detection: bitcoin miners, DNS exfil, SSH brute force |
| Audit | CloudTrail | Log inmutable de toda acción en la cuenta |
| Compliance | AWS Config + Security Hub | Reglas tipo "ningún S3 público", "RDS cifrado", etc. |

---

## 📋 Checklist mínimo de producción

Antes de declarar la migración "viva":

- [ ] `terraform apply` (o `cdk deploy`) levanta toda la infra desde cero en <30 min.
- [ ] `https://pdsl.<dominio>/php/01..12/health` → 200.
- [ ] `https://pdsl.<dominio>/py/01..12/health` → 200.
- [ ] `https://pdsl.<dominio>/node/01..12/health` → 200.
- [ ] `https://pdsl.<dominio>/java/01..12/health` → 200.
- [ ] `https://pdsl.<dominio>/dotnet/01..12/health` → 200.
- [ ] ALB con TLS (ACM cert) y política `TLS13-1-2-2021-06` mínimo.
- [ ] WAF rate-based rule activa con threshold conservador (2000 req/5min/IP global, 50 req/5min en `/node/11/*`).
- [ ] Cognito User Pool funcional, **al menos en las rutas mutantes** (`/reset-lab`, `/share-knowledge`, `/cutover/advance`).
- [ ] RDS con `deletion_protection=true` y backups automated 7 días.
- [ ] Secrets Manager con rotación habilitada (mínimo cada 90 días).
- [ ] CloudWatch alarms sobre: `5xx_rate>1%`, `target_response_time_p99>1s`, `fargate_cpu_utilization>80%`, `rds_cpu_utilization>80%`.
- [ ] AWS Budgets con alerta a USD 50 y USD 150.
- [ ] CloudTrail organization trail a S3 cifrado con retención >= 90 días.
- [ ] GuardDuty habilitado.
- [ ] Tags de costo (`Project=pdsl`, `Environment=prod`, `Owner=<tu-email>`) en todos los recursos.
- [ ] Documentado en este archivo el **costo real del primer mes** vs estimado (ver sección "Lecciones del primer mes" abajo cuando aplique).

---

## 🧪 Qué casos del lab ganan profundidad al estar en AWS

| Caso | Qué se enriquece en AWS |
|---|---|
| 01 · API latency | RDS Performance Insights + CloudWatch contention metrics |
| 02 · N+1 | Slow query log de RDS a CloudWatch (solo para el stack PHP — los otros 4 corren SQLite efímero) |
| 03 · Observabilidad | X-Ray traces reales + AMG dashboards con correlation_id |
| 04 · Timeout chain | ALB target timeout configurable + circuit breaker delegado a App Mesh si se quiere |
| 05 · Memory pressure | Container Insights con OOM events de Fargate |
| 06 · Pipeline roto | GitHub Actions + ECS rolling deploy con rollback automático |
| 07-08 · Strangler / extraction | ALB weighted target groups (canary 10/90) |
| 09 · Integración externa | API Gateway + WAF + retries con SQS DLQ |
| 10 · Sobre-dimensionado | Comparar Lambda vs Fargate en factura real |
| 11 · Reportes pesados | RDS read replica + jobs Fargate en cluster separado |
| 12 · Single point of knowledge | Runbooks en Systems Manager + Incident Manager |

---

## 💰 Reglas de oro para mantener la factura sana

- **Apagar lo que no se usa:** EventBridge Scheduler para detener ECS services y RDS fuera de horario laboral (-50% fácil sobre la base 24x7).
- **NAT Gateway es el villano oculto** (~USD 33/mes solo por existir): considerar **VPC Endpoints** para S3/ECR/Secrets Manager y eliminar NAT si las tasks no necesitan internet de salida. Los gateway endpoints (S3, DynamoDB) son gratuitos.
- **AWS Free Tier** cubre 12 meses el primer año: 750h/mes de RDS t4g.micro, 1M requests Lambda, 5 GB CloudWatch — la factura del primer año cae a la mitad.
- **AWS Budgets** con alarma a USD 50/mes y a USD 100/mes — la peor sorpresa cloud no es el costo, es no enterarse.
- **Fargate Spot 70/30** sobre tasks que toleran reinicio: ahorra ~70% sobre la porción Spot. No usar Spot para el worker case01 ni para RDS.
- **CloudFront cache** sobre lecturas idempotentes absorbe ~90% de hits al origen — el costo del backend cae proporcional.
- **Apagar AMG fuera de horario:** USD 9/mes por editor es barato pero acumula si no se usa.

---

## 🚧 Lo que esta guía no resuelve

- **Backups y restore drills.** RDS automated backups están, pero el drill periódico de restore-en-cuenta-paralela no se cubre acá (sería trabajo del caso 19/20 del [ROADMAP](ROADMAP.md)).
- **Multi-región activo-activo.** Fuera de scope. Single-region us-east-1 con multi-AZ es suficiente para un portfolio público.
- **Compliance específico** (HIPAA, PCI-DSS, SOC2). Este lab no maneja datos sensibles, no aplica. Si tu caso de uso sí, agregá AWS Config rules específicas del framework + Audit Manager.
- **Cost optimization avanzada.** Reserved Instances / Savings Plans / Compute Savings tienen sentido a partir de 12+ meses de uso sostenido — no para un lab que probablemente escale a 0 fuera de demos.
- **Vendor lock-in mitigation.** ALB rules, IAM, AMP/AMG no son portables. La ruta de salida sigue siendo los 5 composes originales (`compose.root.yml` y compañía) — mantenerlos verdes en CI.

---

## 🚦 Paso a paso de migración detallado (Ruta 1 — ECS Fargate)

### Fase 0 — Pre-requisitos (Día 0)

```bash
# Cuenta AWS con MFA en root, IAM Identity Center activado
aws --version                # >= 2.15
aws configure sso
terraform -version           # >= 1.7  (o cdk >= 2.140)
```

| Paso | Acción | Verificación |
|---|---|---|
| 0.1 | Crear cuenta AWS, activar MFA root | login SSO funcional |
| 0.2 | Registrar dominio en Route 53 (opcional) | NS delegado |
| 0.3 | Configurar OIDC GitHub Actions ↔ IAM role | workflow puede `AssumeRoleWithWebIdentity` |
| 0.4 | Definir presupuesto y alerta en AWS Budgets | email recibido en threshold simulado |

### Fase 1 — Networking base (Día 1)

```
VPC 10.0.0.0/16
├── subnet-public-a   10.0.0.0/24    (AZ a) ──▶ IGW
├── subnet-public-b   10.0.1.0/24    (AZ b) ──▶ IGW
├── subnet-private-a  10.0.10.0/24   (AZ a) ──▶ NAT
└── subnet-private-b  10.0.11.0/24   (AZ b) ──▶ NAT
```

- 2 AZ mínimo (requerido por ALB y RDS Multi-AZ futuro).
- 1 NAT Gateway para ahorro (en producción seria: 1 por AZ).
- Security Groups: `sg-alb` (80/443 desde 0.0.0.0), `sg-tasks` (8080-8500 desde sg-alb), `sg-rds` (5432 desde sg-tasks).

### Fase 2 — Imágenes en ECR (Día 1)

```bash
# Crear repos (uno por stack + portal + worker)
for repo in portal php-lab python-lab node-lab java-lab dotnet-lab case01-worker; do
  aws ecr create-repository --repository-name pdsl/$repo
done

# Build & push multi-arch ARM64 (para t4g)
docker buildx build --platform linux/arm64 \
  -f docker/php/Dockerfile \
  -t <acct>.dkr.ecr.us-east-1.amazonaws.com/pdsl/php-lab:latest \
  --push .
```

### Fase 3 — Datos (Día 2)

- Crear 2 instancias RDS PostgreSQL 16 (`db.t4g.micro`, single-AZ, 20 GB gp3, backup 7 días) — **solo para el stack PHP**. Los otros stacks usan SQLite embebido en filesystem efímero.
- Cargar el seed inicial con los `db/init/*.sql` actuales:

```bash
psql "postgres://problemlab:***@case01.xxxxx.us-east-1.rds.amazonaws.com:5432/problemlab" \
  -f cases/01-api-latency-under-load/php/db/init/01-schema.sql
```

- Guardar credenciales en Secrets Manager y referenciarlas desde la task definition con `secrets:` (no `environment:`).

### Fase 4 — Cluster ECS y task definitions (Días 3-4)

- Crear cluster `pdsl-prod` con capacity provider Fargate + Fargate Spot 70/30 para ahorrar.
- Por cada hub: una task definition de 512 CPU / 1024 MiB (ARM64) que corre el dispatcher con sus 12 procesos internos.
- Worker case01 como service separado (256 CPU / 512 MiB).
- 6 services detrás de un único ALB con listener rules por path:

| Path rule | Target group | Notas |
|---|---|---|
| `/`, `/static/*` | `tg-portal` | Portal HTML (PHP + Apache) |
| `/php/*` | `tg-php-lab` | Dispatcher PHP (17 casos internos); casos 01/02 conectan a RDS pg-01/pg-02 |
| `/py/*` | `tg-python-lab` | Dispatcher Python con 17 casos internos |
| `/node/*` | `tg-node-lab` | Dispatcher Node con 17 casos internos |
| `/java/*` | `tg-java-lab` | Dispatcher Java con 17 casos internos |
| `/dotnet/*` | `tg-dotnet-lab` | Dispatcher .NET con 17 casos internos |

### Fase 5 — Edge (Día 4)

- ACM certificate para `pdsl.<tudominio>` (validación DNS).
- CloudFront distribution con origin = ALB, cache policy `CachingDisabled` para `/*/0X/*` (siempre dinámico) y `CachingOptimized` para `/static/*`.
- Route 53 alias A record → CloudFront.

### Fase 6 — Observabilidad (Día 5)

- Workspace AMP + scrape config apuntando a los endpoints de métricas internos (vía ADOT collector como sidecar o como service propio).
- Workspace AMG vinculado a AMP + CloudWatch + RDS Performance Insights.
- Importar dashboards existentes desde `cases/01-api-latency-under-load/shared/observability/grafana/dashboards/`.

### Fase 7 — CI/CD (Día 6)

```yaml
# .github/workflows/deploy-aws.yml (esquema)
permissions:
  id-token: write
  contents: read

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: aws-actions/configure-aws-credentials@v4
        with:
          role-to-assume: arn:aws:iam::<acct>:role/gh-deploy
          aws-region: us-east-1
      - run: docker buildx build --push ...
      - run: aws ecs update-service --cluster pdsl-prod --service php-lab --force-new-deployment
```

### Fase 8 — Cutover y validación (Día 7)

| Check | Comando | Esperado |
|---|---|---|
| ALB sano | `curl -I https://pdsl.<dom>/healthz` | 200 |
| Caso 01 UI | navegador → `https://pdsl.<dom>/php/01/` | dashboard render |
| Probe latencia | `ab -n 200 -c 10 https://pdsl.<dom>/php/01/api/orders` | p95 < 500 ms |
| Grafana | `https://g-xxxx.grafana-workspace.us-east-1.amazonaws.com` | dashboards visibles |
| Cost alarm | AWS Budgets | dispara a USD 50 simulado |

### Fase 9 — Hardening posterior (Semana 2)

- WAF managed rules (`AWSManagedRulesCommonRuleSet`, `AWSManagedRulesBotControlRuleSet`).
- GuardDuty habilitado en la cuenta.
- CloudTrail organization trail a S3 cifrado.
- `deletion_protection=true` en RDS productivo.
- Multi-AZ para RDS si se promueve a "demo permanente".

---

## 🛠️ Servicios AWS involucrados (catálogo completo)

| Categoría | Servicio | Para qué se usa |
|---|---|---|
| Compute | ECS Fargate | Default — 9 services (7 hubs + portal + worker) |
| Compute alt | Lambda container image | Ruta 2 serverless |
| Compute alt | EKS | Ruta 3, K8s managed |
| Red | VPC, subnets pub/priv, NAT GW, IGW, SG | Aislamiento, egress controlado |
| Red | Route 53 | DNS y health checks |
| Red | ACM | Certificado TLS gratis |
| Red | ALB / API Gateway HTTP API | Routing por path |
| Red | CloudFront | CDN + cache + WAF gestionado |
| Red | AWS WAF | Managed rules (OWASP), rate-based, bot control |
| Datos | RDS PostgreSQL t4g.micro | Casos 01 y 02 stack PHP |
| Datos | Aurora Serverless v2 | Alternativa con auto-pause (ruta Lambda) |
| Datos | DynamoDB | State del lab (reemplaza `/tmp/state.json`) |
| Datos | S3 | Assets estáticos, logs, backups |
| Observabilidad | CloudWatch Logs / Metrics / Alarms | Base |
| Observabilidad | AWS X-Ray | Tracing distribuido (caso 03) |
| Observabilidad | AMP | Reemplaza prom/prometheus |
| Observabilidad | AMG | Reemplaza grafana, con SSO |
| Observabilidad | RDS Performance Insights | DB observability sin sidecar |
| Seguridad | IAM roles por task | Privilegio mínimo |
| Seguridad | Secrets Manager | Credenciales rotables |
| Seguridad | SSM Parameter Store | Config no sensible |
| Seguridad | GuardDuty | Threat detection on-by-default |
| Seguridad | CloudTrail | Audit log inmutable |
| CI/CD | ECR | Registry privado |
| CI/CD | GitHub Actions + OIDC a IAM | Build & deploy sin static keys |
| IaC | Terraform o AWS CDK (TS) | Infra reproducible |
| Costos | AWS Budgets + Cost Anomaly Detection | Alertas presupuestarias |

---

## 📦 Infrastructure as Code

Estructura propuesta dentro del propio repo (cuando se materialice):

```
infra/aws/
├── terraform/
│   ├── main.tf
│   ├── network.tf          # VPC, subnets, NAT, SG
│   ├── ecs.tf              # cluster + 6 services + task defs
│   ├── rds.tf              # 2 RDS para PHP
│   ├── alb.tf              # ALB + 6 target groups + listener rules
│   ├── edge.tf             # CloudFront + WAF + Cognito
│   ├── observability.tf    # AMP + AMG + CloudWatch alarms
│   └── variables.tf
└── cdk/                    # alternativa TypeScript
    ├── bin/pdsl.ts
    └── lib/{network,data,compute,edge,observability}-stack.ts
```

Comando objetivo:

```bash
cd infra/aws/terraform
terraform init
terraform plan  -var "domain_name=pdsl.<dominio>"
terraform apply -var "domain_name=pdsl.<dominio>"
```

> Mientras `infra/aws/` no exista en `main`, esta migración sigue en estado `PLANIFICADO` según la [taxonomía de madurez del README](README.md#-madurez-actual).

---

## ⚠️ Riesgos y trade-offs honestos

- **Costo idle real:** aun apagando las tasks, NAT + ALB + Route 53 dejan un piso de ~USD 50/mes en la ruta ECS. Si el lab no recibe tráfico, **Ruta 2 (Lambda) es objetivamente mejor**.
- **NAT Gateway tax:** ~USD 33/mes solo por existir. Mitigar con VPC Endpoints (gateway endpoints son gratis para S3 y DynamoDB).
- **Quotas iniciales:** cuentas nuevas tienen límites bajos de vCPU Fargate, EIPs. Levantar tickets de service quota con tiempo.
- **RDS no es free** después del primer año. Si el costo año 2 importa, evaluar Aurora Serverless v2 con `min_capacity=0.5` y auto-pause.
- **Vendor lock-in:** ALB rules, IAM, AMP/AMG no son portables. Mantener `compose.*.yml` original como ruta de salida.
- **Observabilidad doble factura:** si se usa AMP **y** CloudWatch para lo mismo, se paga dos veces. Decidir cuál es la fuente de verdad por métrica.
- **Asimetría documentada se preserva:** los 4 stacks no-PHP siguen con substrato simulado en caso 01 y SQLite embebido en caso 02. AWS no resuelve esto — es deuda explícita en el [ROADMAP Eje 2](ROADMAP.md#fidelidad-universal-de-caso-01).

---

## 🔭 Roadmap post-migración (opcional)

| Sprint | Entrega |
|---|---|
| +1 | Multi-AZ RDS + ALB sticky para caso 11 |
| +2 | Canary deploys con CodeDeploy en caso 06 |
| +3 | Chaos engineering con AWS FIS sobre caso 04 y 05 |
| +4 | Cost dashboard público con AWS Cost Explorer API + AMG |
| +5 | Migrar 1 caso a Lambda (caso 10) para comparar factura real Fargate vs Lambda |

---

## 🔗 Referencias

- [AWS Pricing Calculator](https://calculator.aws/)
- [ECS Fargate pricing](https://aws.amazon.com/fargate/pricing/)
- [RDS PostgreSQL pricing](https://aws.amazon.com/rds/postgresql/pricing/)
- [Amazon Managed Prometheus](https://aws.amazon.com/prometheus/)
- [Amazon Managed Grafana](https://aws.amazon.com/grafana/)
- [AWS WAF pricing](https://aws.amazon.com/waf/pricing/)
- [Well-Architected Framework](https://aws.amazon.com/architecture/well-architected/)
- [GitHub Actions OIDC con AWS](https://docs.github.com/en/actions/deployment/security-hardening-your-deployments/configuring-openid-connect-in-amazon-web-services)
- Documentos relacionados del repo: [SECURITY.md](SECURITY.md) · [ARCHITECTURE.md](ARCHITECTURE.md) · [ROADMAP.md](ROADMAP.md)

---

> Este documento es un **plan**, no un estado. La migración real se considera entregada cuando `infra/aws/` existe en `main`, los healthchecks pasan en el dominio público y el primer mes de factura está documentado en este archivo.
