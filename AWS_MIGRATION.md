# ☁️ AWS Migration Plan — Problem-Driven Systems Lab

[![AWS](https://img.shields.io/badge/Cloud-AWS-FF9900?logo=amazonaws&logoColor=white)](https://aws.amazon.com/)
[![ECS](https://img.shields.io/badge/Container-ECS%20Fargate-FF9900?logo=amazonecs&logoColor=white)](https://aws.amazon.com/ecs/)
[![EKS](https://img.shields.io/badge/Kubernetes-EKS-326CE5?logo=kubernetes&logoColor=white)](https://aws.amazon.com/eks/)
[![RDS](https://img.shields.io/badge/Database-RDS%20PostgreSQL-336791?logo=postgresql&logoColor=white)](https://aws.amazon.com/rds/postgresql/)
[![IaC](https://img.shields.io/badge/IaC-Terraform%20%7C%20CDK-7B42BC?logo=terraform&logoColor=white)](https://www.terraform.io/)
[![Status](https://img.shields.io/badge/Estado-Plan%20de%20migraci%C3%B3n-blue)](#)

> Plan tecnico-operativo y honesto para migrar el laboratorio (42 endpoints operativos: 12 PHP + 12 Python + 12 Node + 6 Java + portal + observabilidad) desde Docker Compose local hacia AWS, con tres rutas alternativas, costos reales estimados y trade-offs explicitos.

---

## 🎯 Executive Summary

- El laboratorio hoy corre como un conjunto de servicios Docker Compose: **portal PHP** + **4 hubs simetricos por lenguaje** (`pdsl-php-lab` :8100, `pdsl-python-lab` :8200, `pdsl-node-lab` :8300 con 12 subprocesos cada uno; `pdsl-java-lab` :8400 con 6 subprocesos para casos 01-06) + **2 instancias PostgreSQL** (casos 01/02 PHP) + **Prometheus** + **Grafana** + **worker** background del caso 01.
- AWS ofrece **al menos tres rutas validas** segun presupuesto, foco pedagogico y madurez operacional deseada (serverless, contenedores manejados, Kubernetes).
- La opcion recomendada para un portafolio publico orientado a costo/utilidad es **ECS Fargate + RDS PostgreSQL + ALB + CloudWatch + AMP/AMG**, con un costo estimado de **USD ~120–135/mes** ejecutandolo 24x7, o **USD ~65–85/mes** apagado fuera de horario.
- Toda la migracion se publica como **Infrastructure as Code (Terraform o AWS CDK)** dentro del propio repo, manteniendo la promesa de reproducibilidad.
- La narrativa del repo se preserva: los 12 casos siguen siendo *problem-driven*, ahora con tres capitulos adicionales — **costo, escalabilidad cloud-native** y **como AWS mitiga los hallazgos abiertos del [`SECURITY.md`](SECURITY.md)** (sin auth, DoS del event loop, sin rate limiting, etc.).

---

## 🧭 Inventario actual a migrar

Mapa rapido de lo que vive hoy en `compose.root.yml`, `compose.python.yml`, `compose.nodejs.yml` y `compose.java.yml`:

| Servicio actual | Imagen / runtime | Recurso | Estado | Equivalente AWS sugerido |
| --- | --- | --- | --- | --- |
| `portal-php8` | PHP 8 + Apache | 1 contenedor | OPERATIVO | ECS Fargate Service · S3+CloudFront (si se hace estatico) |
| `php-lab` (PHP dispatcher, 12 casos internos `:9001-:9012`) | PHP 8.3 + tini | 1 contenedor | OPERATIVO | ECS Fargate Service · Lambda Container |
| `case01-worker` | PHP CLI loop | worker | OPERATIVO | ECS Fargate Service (long-running) · EventBridge Scheduler + Lambda |
| `case01-db`, `case02-db` | postgres:16-alpine | 2 DBs | OPERATIVO | **RDS PostgreSQL** (db.t4g.micro) · Aurora Serverless v2 |
| `case01-postgres-exporter` | postgres-exporter | metrics | OPERATIVO | RDS Performance Insights · Prometheus en AMP |
| `case01-prometheus` | prom/prometheus | TSDB | OPERATIVO | **AMP** (Amazon Managed Prometheus) |
| `case01-grafana` | grafana 11 | UI | OPERATIVO | **AMG** (Amazon Managed Grafana) |
| `python-lab` (Python dispatcher, 12 casos internos `:9001-:9012`) | Python 3.12 | 1 contenedor | OPERATIVO | ECS Fargate Service · Lambda Container |
| `node-lab` (Node dispatcher, 12 casos internos `:9101` + `:9002-:9012`) | Node.js 20 | 1 contenedor | OPERATIVO | ECS Fargate Service · Lambda Container |
| `java-lab` (Java dispatcher, 6 casos internos `:9401-:9406`) | Java 21 (eclipse-temurin) | 1 contenedor | OPERATIVO (parcial, 01-06) | ECS Fargate Service · Lambda Container |

**Volumenes con estado**: `pgdata_case01`, `pgdata_case02`, `prometheus_case01`, `grafana_case01` — todos pasan a servicios manejados (RDS / AMP / AMG) y desaparecen como volumenes EFS/EBS.

---

## 🏗️ Topologia objetivo (Opcion A · recomendada)

```
Internet
  |
  `──▶ Route 53        (DNS · pdsl.tudominio.dev)
         |
         `──▶ CloudFront   (CDN · TLS · WAF · cache estatico)
                |
                `──▶ ALB   (path routing por lenguaje + caso)
                       |
                       |── /                 → ECS Fargate · portal-php
                       |── /php/01..12/*     → ECS Fargate · php-lab    (1 task, 12 casos internos)
                       |                                              ──▶ RDS pg-01,02
                       |                                              `──▶ ECS Fargate · worker-01
                       |── /py/01..12/*      → ECS Fargate · python-lab (1 task, 12 casos internos)
                       |── /node/01..12/*    → ECS Fargate · node-lab   (1 task, 12 casos internos)
                       `── /java/01..06/*    → ECS Fargate · java-lab   (1 task, 6 casos internos)

Observabilidad           Plataforma                   Seguridad / Edge
|- CloudWatch Logs       |- ECR  (registry imagenes)  |- CloudFront + AWS WAF (rate limit, OWASP)
|- CloudWatch Metrics    |- Secrets Manager (DB creds)|- Cognito User Pool (auth opcional)
|- AWS X-Ray (traces)    |- SSM Parameter Store       |- ACM (TLS)
|- AMP (Prometheus)      |- IAM roles por task        |- VPC · 2 AZ · subnets pub/priv
|- AMG (Grafana · SSO)   `- (ver SECURITY.md mapping) |- GuardDuty + CloudTrail
`- RDS Performance Insights
```

**Decisiones clave de esta opcion:**

- **ECS Fargate** y no EC2: cero parches de SO, factura por vCPU/RAM realmente consumidos.
- **RDS** y no Postgres en contenedor: backups, snapshots, upgrades manejados — alineado con el caso `01` y `02` que justamente hablan de presion de DB.
- **AMP + AMG** y no Prometheus/Grafana auto-hospedados: mismo dashboard, sin operar TSDB.
- **ALB con path routing por lenguaje** (`/php/*`, `/py/*`, `/node/*`, `/java/*`) replica el modelo de los 4 hubs locales (`compose.root.yml`, `compose.python.yml`, `compose.nodejs.yml`, `compose.java.yml`) — el visitante elige stack en la URL, no hay 42 endpoints expuestos.
- **CloudFront + WAF** delante para TLS, cache del portal, rate limiting y proteccion ante los hallazgos del [`SECURITY.md`](SECURITY.md) (DoS del caso 11, ausencia de auth, etc. — ver mapping abajo).
- **Cognito** opcional para resolver el hallazgo A1 (sin auth) sin tocar codigo del lab.

---

## 🧩 Opciones alternativas

### Opcion B · Serverless puro (Lambda + API Gateway + Aurora Serverless v2)

```
Internet
  `──▶ CloudFront
         `──▶ API Gateway HTTP API
                |── Lambda /01  (container image)
                |── Lambda /02  (container image)
                |── …
                `── Lambda /12  (container image)
                       |
                       `──▶ Aurora Serverless v2  (auto-pause · 0.5 ACU min)

Observabilidad: CloudWatch + X-Ray + AMG
```

- **Pro**: factura por request real → cuando nadie visita el portfolio, casi USD 0. Aurora Serverless v2 puede escalar a 0.5 ACU.
- **Contra**: cold starts visibles para PHP en Lambda (200–800 ms primer hit), workers de larga duracion no encajan, Prometheus self-scrape no es trivial.
- **Recomendado si**: el repo lo visitan recruiters de forma esporadica y se quiere maxima frugalidad.

### Opcion C · Kubernetes manejado (EKS + RDS + AMP/AMG)

```
Internet
  `──▶ ALB Ingress Controller
         `──▶ EKS cluster  (2 AZ · Karpenter / Managed Node Group)
                |── deploy: portal
                |── deploy: php-cases   (12 pods)
                |── deploy: python-hub
                |── deploy: node-hub
                `── deploy: java-hub (01-06)
                       |
                       `──▶ RDS  +  AMP  +  AMG
```

- **Pro**: portafolio mas valioso para audiencia DevOps/Platform Engineering — demuestra Helm, manifests, HPA, PDB, Karpenter.
- **Contra**: costo base alto (control plane EKS = USD 73/mes solo por existir) + nodos. No tiene sentido para un lab que vive sin trafico.
- **Recomendado si**: el objetivo explicito es senalizar capacidad K8s/CKA.

### Opcion D · Lift & shift minimo (EC2 + Docker Compose)

- **Pro**: cambio cero. `docker compose up` sobre una `t4g.small` y listo.
- **Contra**: entrega cero del valor cloud-native — pierdes la narrativa, sigues parchando SO, sin HA, sin escalado.
- **Recomendado si**: solo quieres validar funcionalmente que todo corre fuera de tu laptop antes de migrar de verdad.

### 🧮 Matriz de decision

| Criterio | A · ECS Fargate | B · Lambda | C · EKS | D · EC2 |
| --- | --- | --- | --- | --- |
| Costo idle 24x7 | Medio | **Muy bajo** | Alto | Bajo |
| Tiempo de migracion | 1–2 semanas | 2–3 semanas | 2–4 semanas | **1–2 dias** |
| Complejidad operacional | Media | Media | **Alta** | Baja |
| Cold start | Ninguno | Visible | Ninguno | Ninguno |
| Workers long-running | ✅ | ⚠️ (EventBridge) | ✅ | ✅ |
| Valor narrativo del repo | **Alto** | Alto | Muy alto | Bajo |
| Recomendacion default | ⭐ | Si hay <100 visitas/dia | Si el target es DevOps senior | No |

---

## 🛠️ Servicios AWS involucrados (catalogo completo)

| Categoria | Servicio | Para que se usa aqui |
| --- | --- | --- |
| Compute | **ECS Fargate** | Portal, 12 casos PHP, dispatcher Python, worker |
| Compute alt | **Lambda (container image)** | Casos sin estado en Opcion B |
| Compute alt | **EKS** | Opcion C, K8s manejado |
| Compute alt | **EC2** | Opcion D o bastion |
| Red | **VPC, subnets pub/priv, NAT GW, IGW, SG** | Aislamiento de red, egress controlado |
| Red | **Route 53** | DNS y health checks |
| Red | **ACM** | Certificado TLS gratis |
| Red | **ALB / API Gateway HTTP API** | Routing por path a cada caso |
| Red | **CloudFront** | CDN + cache + WAF gestionado |
| Red | **AWS WAF** | Reglas managed (OWASP top 10, bot control) |
| Datos | **RDS PostgreSQL (t4g.micro)** | Casos 01 y 02 |
| Datos | **Aurora Serverless v2** | Alternativa con auto-pause |
| Datos | **S3** | Assets estaticos del portal, logs, backups |
| Observabilidad | **CloudWatch Logs / Metrics / Alarms** | Logs, metricas y alertas base |
| Observabilidad | **AWS X-Ray** | Tracing distribuido (caso 03) |
| Observabilidad | **Amazon Managed Prometheus (AMP)** | Reemplazo de prom/prometheus |
| Observabilidad | **Amazon Managed Grafana (AMG)** | Reemplazo de grafana, con SSO |
| Observabilidad | **RDS Performance Insights** | DB observability sin sidecar |
| Seguridad | **IAM roles por servicio (task roles)** | Privilegio minimo por contenedor |
| Seguridad | **Secrets Manager** | Credenciales de DB rotables |
| Seguridad | **SSM Parameter Store** | Config no sensible |
| Seguridad | **GuardDuty** (opcional) | Threat detection on-by-default |
| CI/CD | **ECR** | Registry privado para imagenes |
| CI/CD | **GitHub Actions + OIDC a IAM** | Build & deploy sin static keys |
| CI/CD | **CodeBuild / CodePipeline** | Alternativa nativa AWS |
| IaC | **Terraform** o **AWS CDK (TypeScript)** | Infra reproducible |
| Costos | **AWS Budgets + Cost Anomaly Detection** | Alertas si excede USD X/mes |

---

## 💰 Estimacion de costos (region us-east-1, May 2026)

> Los precios cambian — usar la [calculadora oficial](https://calculator.aws/) antes de comprometerse. Los numeros de abajo asumen trafico bajo de portfolio (cientos de visitas/mes).

### Opcion A · ECS Fargate (recomendada) · 24x7

| Servicio | Configuracion | Costo mensual estimado |
| --- | --- | --- |
| ECS Fargate — portal | 0.25 vCPU / 0.5 GB · 1 servicio | ~ USD 3.5 |
| ECS Fargate — php-lab | 0.5 vCPU / 1 GB · dispatcher con 12 casos internos | ~ USD 7 |
| ECS Fargate — python-lab | 0.5 vCPU / 1 GB · dispatcher con 12 casos internos | ~ USD 7 |
| ECS Fargate — node-lab | 0.5 vCPU / 1 GB · dispatcher con 12 casos internos | ~ USD 7 |
| ECS Fargate — java-lab | 0.5 vCPU / 1 GB · dispatcher con 6 casos internos (01-06) | ~ USD 7 |
| ECS Fargate — worker case01 | 0.25 vCPU / 0.5 GB | ~ USD 3.5 |
| RDS PostgreSQL — case01 | db.t4g.micro · 20 GB gp3 · single-AZ | ~ USD 13 |
| RDS PostgreSQL — case02 | db.t4g.micro · 20 GB gp3 · single-AZ | ~ USD 13 |
| ALB | 1 ALB · trafico bajo | ~ USD 18 |
| NAT Gateway | 1 NAT (single-AZ) | ~ USD 33 |
| CloudFront | <50 GB egress | ~ USD 1–3 |
| AWS WAF | managed rules + rate-limit | ~ USD 6 |
| Route 53 | 1 hosted zone | ~ USD 0.5 |
| CloudWatch Logs | 5 GB ingest + 10 GB store | ~ USD 4 |
| AMP | <10M samples ingeridos | ~ USD 2 |
| AMG | 1 editor user | ~ USD 9 |
| ECR | 5 GB | ~ USD 0.5 |
| Secrets Manager | 4 secrets | ~ USD 1.6 |
| **Total aproximado 24x7** | | **USD ~135–150 / mes** |

> **Nota sobre los 4 hubs simetricos**: cada hub (PHP/Python/Node/Java) corre sus casos en un solo task Fargate como subprocesos internos, exactamente como en local. Antes del refactor del PHP dispatcher, PHP usaba 12 services separados (~USD 42/mes). Ahora son 4 hubs simetricos × ~USD 7 = USD 28/mes para los 42 endpoints (12 PHP + 12 Python + 12 Node + 6 Java). El trade-off: si un caso tiene memory leak, puede afectar a los otros del mismo hub. Para los servicios reales del caso 01 (PostgreSQL, worker, observabilidad) NO se aplica — siguen siendo contenedores/services independientes porque son servicios distintos del lenguaje.

### Opcion A · ECS Fargate · apagado fuera de horario laboral (cron 8h x 5 dias)

> Una **EventBridge rule** detiene los services en la noche/fines de semana. NAT y ALB siguen facturando hora.

| Componente | Ahorro |
| --- | --- |
| ECS Fargate (4 hubs + worker + portal) | -75% → ~ USD 9 |
| RDS (stop max 7 dias por ciclo) | -60% → ~ USD 10 |
| ALB / NAT / Route 53 / CloudFront / WAF | sin cambio |
| **Total estimado** | **USD ~70–90 / mes** |

### Opcion B · Lambda + Aurora Serverless v2 · pay-per-use

| Servicio | Asuncion | Costo |
| --- | --- | --- |
| Lambda (12 PHP + 12 Python + 12 Node + 6 Java = 42 funciones container) | <10 000 invocaciones / mes total | ~ USD 0–1 |
| API Gateway HTTP API | <1M requests / mes | ~ USD 1 |
| Aurora Serverless v2 | min 0.5 ACU, ~30 min/dia activo | ~ USD 25–40 |
| CloudFront / Route 53 / WAF | igual que A | ~ USD 11 |
| CloudWatch | base | ~ USD 2 |
| **Total** | | **USD ~40–55 / mes** |

> Lambda escala mejor con la **paridad multi-stack completa**: 42 funciones que comparten edge (CloudFront + WAF) y un solo backend (Aurora Serverless v2). El cold start sigue siendo visible (200-800ms en PHP, 100-300ms en Node, 200-500ms en Python, 800-2000ms en Java sin SnapStart — peor caso del grupo).

### Opcion C · EKS

| Servicio | Costo |
| --- | --- |
| EKS control plane | USD 73 fijo |
| 2 nodos t4g.medium (HA) | ~ USD 50 |
| RDS x2, ALB, NAT, AMP, AMG, CloudFront | ~ USD 95 |
| **Total** | **USD ~220 / mes** |

### Opcion D · EC2 t4g.small con Docker Compose

| Servicio | Costo |
| --- | --- |
| 1x t4g.small | ~ USD 12 |
| 30 GB EBS gp3 | ~ USD 2.5 |
| Elastic IP | USD 0 (en uso) |
| Egress <50 GB | ~ USD 4 |
| **Total** | **USD ~20 / mes** |

### 🪙 Reglas de oro para mantener la factura sana

- **Apagar lo que no se usa**: EventBridge Scheduler para detener ECS services y RDS fuera de horario (-50% facil).
- **NAT Gateway es el villano oculto** (~USD 33/mes solo por existir): considerar **VPC Endpoints** para S3/ECR y eliminar NAT si las tasks no necesitan internet de salida.
- **AWS Free Tier** cubre 12 meses el primer ano: 750h/mes de RDS t4g.micro, 1M requests Lambda, 5 GB CloudWatch — la factura del primer ano cae a la mitad.
- **AWS Budgets** con alarma a USD 50/mes y a USD 100/mes.

---

## 🚦 Paso a paso de migracion (Opcion A)

### Fase 0 · Pre-requisitos (Dia 0)

```bash
# Cuenta AWS con MFA en root, IAM Identity Center activado.
# AWS CLI v2 y credenciales OIDC para GitHub Actions.
aws --version
aws configure sso

# Terraform o CDK
terraform -version   # >= 1.7
# o
npm i -g aws-cdk     # >= 2.140
```

| Paso | Accion | Verificacion |
| --- | --- | --- |
| 0.1 | Crear cuenta AWS, activar MFA root, crear usuario IAM Identity Center | Login SSO funcional |
| 0.2 | Solicitar/registrar dominio en Route 53 (opcional) | NS delegado |
| 0.3 | Configurar OIDC GitHub Actions ↔ IAM role | Workflow puede `sts:AssumeRoleWithWebIdentity` |
| 0.4 | Definir presupuesto y alerta en AWS Budgets | Email recibido en threshold |

### Fase 1 · Networking base (Dia 1)

```
VPC 10.0.0.0/16
|- subnet-public-a   10.0.0.0/24    (AZ a) ──▶ IGW
|- subnet-public-b   10.0.1.0/24    (AZ b) ──▶ IGW
|- subnet-private-a  10.0.10.0/24   (AZ a) ──▶ NAT
`- subnet-private-b  10.0.11.0/24   (AZ b) ──▶ NAT
```

- 2 AZ minimo (requerido por ALB y RDS Multi-AZ futuro).
- 1 NAT Gateway (para ahorro; en produccion serio: 1 por AZ).
- Security Groups: `sg-alb` (80/443 desde 0.0.0.0), `sg-tasks` (8080 desde sg-alb), `sg-rds` (5432 desde sg-tasks).

### Fase 2 · Imagenes en ECR (Dia 1)

```bash
# Crear repos
aws ecr create-repository --repository-name pdsl/portal-php
aws ecr create-repository --repository-name pdsl/case01-php
# … (uno por servicio o un repo "casos" con tags)

# Build & push (multi-arch arm64 para t4g)
docker buildx build --platform linux/arm64 \
  -f docker/php/Dockerfile \
  --build-arg APP_DIR=cases/01-api-latency-under-load/php/app \
  -t <acct>.dkr.ecr.us-east-1.amazonaws.com/pdsl/case01-php:latest \
  --push .
```

### Fase 3 · Datos (Dia 2)

- Crear **2 instancias RDS PostgreSQL 16** (`db.t4g.micro`, single-AZ, 20 GB gp3, backup 7 dias).
- Cargar el seed inicial con los `db/init/*.sql` actuales:

```bash
psql "postgres://problemlab:***@case01.xxxxx.us-east-1.rds.amazonaws.com:5432/problemlab" \
  -f cases/01-api-latency-under-load/php/db/init/01-schema.sql
```

- Guardar credenciales en **Secrets Manager** y referenciarlas desde la task definition (`secrets:` en lugar de `environment:`).

### Fase 4 · Cluster ECS y task definitions (Dia 3–4)

- Crear cluster `pdsl-prod` con capacity provider Fargate + Fargate Spot 70/30.
- Por cada hub: una task definition de 512 CPU / 1024 MiB (ARM64) que corre el dispatcher con sus procesos internos (12 para PHP/Python/Node, 6 para Java). Worker del caso 01 como service separado (256 CPU / 512 MiB).
- Crear 5 services (portal + php-lab + python-lab + node-lab + worker-01) detras de un solo ALB con **listener rules por path**:

| Path rule | Target group | Notas |
| --- | --- | --- |
| `/`, `/static/*` | `tg-portal` | Portal HTML (PHP+Apache) |
| `/php/*` | `tg-php-lab` | Dispatcher PHP (12 casos internos), casos 01/02 conectan a RDS pg-01/pg-02 |
| `/py/*` | `tg-python-lab` | Dispatcher Python (12 casos internos) |
| `/node/*` | `tg-node-lab` | Dispatcher Node (12 casos internos) |

### Fase 5 · Edge (Dia 4)

- ACM certificate para `pdsl.tudominio.dev` (validacion DNS).
- CloudFront distribution con origin = ALB, cache policy "CachingDisabled" para `/0X/*` y "CachingOptimized" para `/static/*`.
- Route 53 alias A record → CloudFront.

### Fase 6 · Observabilidad (Dia 5)

- Workspace **AMP** + scrape config apuntando a los endpoints de metricas internos (via ADOT collector como sidecar o como service propio).
- Workspace **AMG** vinculado a AMP + CloudWatch + RDS Performance Insights.
- Importar dashboards existentes desde `cases/01-api-latency-under-load/shared/observability/grafana/dashboards/`.

### Fase 7 · CI/CD (Dia 6)

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
      - run: aws ecs update-service --cluster pdsl-prod --service case01 --force-new-deployment
```

### Fase 8 · Cutover y validacion (Dia 7)

| Check | Comando | Esperado |
| --- | --- | --- |
| ALB sano | `curl -I https://pdsl.../healthz` | 200 |
| Caso 01 UI | navegador → `https://pdsl.../01/` | dashboard render |
| Probe latencia | `ab -n 200 -c 10 https://pdsl.../01/api/orders` | p95 < 500 ms |
| Grafana | `https://g-xxxx.grafana-workspace.us-east-1.amazonaws.com` | dashboards visibles |
| Cost alarm | AWS Budgets | dispara a USD 50 simulado |

### Fase 9 · Hardening posterior (semana 2)

- WAF managed rules (`AWSManagedRulesCommonRuleSet`).
- GuardDuty en la cuenta.
- CloudTrail organization trail a S3 cifrado.
- Habilitar `deletion_protection` en RDS productivo.
- Multi-AZ para RDS si se promueve a "demo permanente".

---

## 🛡️ Como AWS resuelve los hallazgos abiertos del [`SECURITY.md`](SECURITY.md)

El [`SECURITY.md`](SECURITY.md) documenta hallazgos del lab que existen **por diseño** (es un laboratorio educativo, no un servicio productivo): sin auth, sin rate limiting, DoS posible del event loop en caso 11, sin atomicidad en escrituras de state, etc. **Migrar a AWS resuelve la mayoria de esos hallazgos sin tocar codigo del lab**, simplemente delegando defensas en servicios manejados.

Esta tabla mapea cada hallazgo a la solucion AWS recomendada:

| # | Hallazgo en SECURITY.md | Severidad si se expone | Mitigacion AWS sin tocar codigo del lab | Costo aproximado |
| --- | --- | --- | --- | --- |
| **A1** | Sin autenticacion en ningun endpoint | Alta | **3 opciones**, en orden de simplicidad: <br/>**a)** **ALB OIDC integration** con **Cognito User Pool** — el ALB intercepta requests no autenticadas y redirige al login Cognito. Cero cambio en el codigo del lab. <br/>**b)** **CloudFront + Lambda@Edge** validando JWT de Cognito o IdP externo (Okta, Auth0, Google). <br/>**c)** **WAF custom rule** validando un header `X-API-Key` contra Secrets Manager (rotable). | Cognito: USD 0 hasta 50K MAU. Lambda@Edge: <USD 1/mes |
| **A2** | DoS del event loop en caso 11 Node (`while(...){}` sincronico bloqueante) | Alta | **WAF rate-based rule**: `RateLimit: 100 requests / 5min por IP, scoped to /node/11/*`. **CloudFront + WAF Bot Control** filtra crawlers automatizados. **ALB target health checks** detectan task colgada → Fargate la reemplaza automaticamente (`HealthCheckGracePeriodSeconds: 30s`). **Auto Scaling** sobre CPU (target 60%) escala horizontalmente. | WAF: USD 6/mes + USD 1 por regla custom |
| **M1** | Mutaciones aceptan cualquier verbo HTTP (`GET /reset-lab` ≡ `DELETE /reset-lab`) | Media | **WAF custom rule**: `if path matches /reset-lab and method != POST then BLOCK`. Definible declarativamente. **API Gateway** (Opcion B serverless) valida verbo nativamente — no llega a Lambda si el metodo no esta en la spec. | Incluido en WAF base |
| **M2** | Reflejo de header `Host` en `probe.php` | Baja | **CloudFront origin request policy** controla que headers llegan al backend (allowlist). **ALB** dropea Host invalido con `if header Host not in (pdsl.tudominio.dev, *.tudominio.dev) then 421`. **WAF managed rule** `AWSManagedRulesCommonRuleSet` cubre Host header attacks comunes. | Incluido |
| **M3** | Sin rate limiting global | Media | **WAF rate-based rules** por path: 1000 req/5min para lecturas, 60 req/5min para `/reset-lab`/`/share-knowledge`/`/cutover/advance`. **CloudFront cache** absorbe lecturas repetidas — el backend ni se entera. **API Gateway throttling** (Opcion B) tiene throttle por endpoint nativo. | Incluido en WAF |
| **M4** | Sin atomicidad en escrituras de state JSON en `/tmp` | Media | **El problema desaparece**: el state se va de `/tmp` a un servicio AWS apropiado: <br/>**a)** **DynamoDB** con `ConditionExpression` para writes condicionales (CAS atomico). <br/>**b)** **RDS** con transacciones SERIALIZABLE. <br/>**c)** **S3 + If-Match ETag** para optimistic locking. <br/>Cualquiera elimina la corrupcion silenciosa que existe con archivos planos compartidos. | DynamoDB: USD 0–5 (PAY_PER_REQUEST) |

### Defensas adicionales que AWS aporta (que el lab no tiene)

| Capa | Servicio | Que protege |
| --- | --- | --- |
| **Edge** | CloudFront | TLS, cache (reduce hits al origen ≈90% en lecturas estaticas), origin shield |
| **Edge** | AWS WAF + AWS Shield Standard | OWASP Top 10 (`AWSManagedRulesCommonRuleSet`), bots (`AWSManagedRulesBotControlRuleSet`), known bad inputs, geo-blocking, IP reputation lists, DDoS L3/L4 (gratuito con AWS Shield Standard) |
| **Identity** | Cognito + IAM Identity Center | Auth de usuarios finales (Cognito) y de operadores (IAM IC con SSO corporativo) |
| **Network** | VPC privadas + Security Groups | Tasks Fargate sin IP publica; el ALB es el unico ingress |
| **Network** | VPC Endpoints | Tasks acceden a S3/ECR/Secrets Manager sin pasar por Internet (NAT) |
| **Application** | IAM task roles | Cada servicio Fargate asume un rol con permisos minimos (least privilege). Caso 01 puede leer su RDS, no la del caso 02 |
| **Secrets** | Secrets Manager + KMS | Rotacion automatica de credenciales DB, cifrado en reposo, audit completo en CloudTrail |
| **Detection** | GuardDuty | ML-based threat detection: bitcoin miners, DNS exfiltration, SSH brute force, comportamiento anomalo |
| **Audit** | CloudTrail | Log inmutable de TODA accion en la cuenta (quien hizo que, cuando, desde donde) — 7 dias gratis, +1 trail a S3 cifrado para retencion larga |
| **Compliance** | AWS Config + Security Hub | Reglas automaticas tipo "ningun S3 bucket publico", "RDS cifrado", "tasks sin IAM role overprivilegiado" |

### Ejemplo concreto: `/node/11/report-legacy?rows=5000000` despues de migrar

Sin AWS, este endpoint puede ser invocado en loop por cualquier IP en LAN para bloquear el event loop del hub Node `:8300` durante 9 segundos por cada 10 requests concurrentes (hallazgo A2).

Despues de migrar:

1. **Cognito** rechaza el request si no hay JWT valido (A1 mitigado).
2. Si pasa Cognito, **WAF rate-based rule** (`/node/11/*` limit 50 req/5min/IP) bloquea el flood (A2 mitigado en el edge).
3. Si por alguna razon llega al backend, **ALB health check** detecta latencia anomala y rota la task antes que afecte a otras requests.
4. **CloudWatch alarm** sobre `event_loop_lag_p99` (publicada por el caso 11 al `/metrics-prometheus`, scrapeada por **AMP**, alarmada via **Container Insights**) notifica via **SNS → email/Slack/PagerDuty** en 60 segundos.
5. **Auto Scaling** lanza una task adicional si el CPU sostenido supera 60% — el costo de la mitigacion se amortiza solo durante el ataque.

El costo total de estas mitigaciones es **~USD 6–10/mes** (WAF + Cognito en su tier gratuito + Container Insights). Un buen ejercicio de "el costo defensivo es bajo cuando se delega correctamente".

---

## 🔐 Seguridad y cumplimiento (controles base)

Mas alla del mapping anterior con `SECURITY.md`, esta es la postura base de la migracion:

- **IAM**: una task role por servicio, principio de privilegio minimo. Nunca claves AWS dentro del contenedor.
- **Secretos**: Secrets Manager con rotacion automatica para RDS.
- **Red**: tasks en subnets privadas, solo ALB en publicas. NAT controlado.
- **TLS**: ACM + politica `ELBSecurityPolicy-TLS13-1-2-2021-06`.
- **WAF**: bloquear OWASP Top 10 + rate limit 2000 req/5min/IP.
- **Logs**: CloudWatch Logs cifrados con KMS, retencion 30 dias para portfolio.
- **Backups**: RDS automated backups 7 dias + snapshot mensual a S3 Glacier.

---

## 📦 Infraestructura como codigo

La intencion es entregar la migracion como `infra/aws/` dentro del propio repo, replicando el espiritu de "todo reproducible":

```
infra/aws/
├── terraform/                      # opcion 1
│   ├── main.tf
│   ├── network.tf
│   ├── ecs.tf
│   ├── rds.tf
│   ├── alb.tf
│   ├── observability.tf
│   └── variables.tf
└── cdk/                            # opcion 2 (TypeScript)
    ├── bin/pdsl.ts
    └── lib/
        ├── network-stack.ts
        ├── data-stack.ts
        ├── compute-stack.ts
        └── edge-stack.ts
```

Comando objetivo:

```bash
cd infra/aws/terraform
terraform init
terraform plan  -var "domain_name=pdsl.tudominio.dev"
terraform apply -var "domain_name=pdsl.tudominio.dev"
```

---

## 🧪 Que casos del lab ganan profundidad al estar en AWS

| Caso | Que se enriquece en AWS |
| --- | --- |
| 01 · API latency | RDS Performance Insights + CloudWatch contention metrics |
| 02 · N+1 | Same + slow query log a CloudWatch |
| 03 · Observabilidad | X-Ray traces reales + AMG dashboards |
| 04 · Timeout chain | ALB target timeout + circuit breaker via App Mesh |
| 05 · Memory pressure | ECS task OOM events + Container Insights |
| 06 · Pipeline roto | GitHub Actions + ECS rolling deploy + rollback |
| 07–08 · Strangler / extraction | ALB weighted target groups (canary 10/90) |
| 09 · Integracion externa | API Gateway + WAF + retries con SQS DLQ |
| 10 · Sobre-dimensionado | Comparar Opcion B (Lambda) vs A (Fargate) en factura real |
| 11 · Reportes pesados | RDS read replica + jobs en Fargate offline |
| 12 · Single point of knowledge | Runbooks en Systems Manager + Incident Manager |

---

## ⚠️ Riesgos y trade-offs honestos

- **Costo idle**: aun apagado, NAT + ALB + Route 53 dejan un piso de ~USD 50/mes. Si el laboratorio no recibe trafico, Opcion B (Lambda) es objetivamente mejor.
- **NAT Gateway "tax"**: el cargo por hora de NAT (~USD 33/mes) puede sorprender. Mitigar con VPC Endpoints (gateway endpoints son gratis para S3 y DynamoDB).
- **Quotas iniciales**: las cuentas nuevas tienen limites bajos (vCPU Fargate, EIPs). Levantar tickets de service quota con tiempo.
- **RDS no es free** despues del primer ano. Si el costo ano 2 importa, evaluar Aurora Serverless v2 con `min_capacity = 0.5` y auto-pause.
- **Vendor lock-in**: ALB rules, IAM, AMP/AMG no son portables. Mantener la opcion `compose.*.yml` original como ruta de salida.
- **Observabilidad doble factura**: si se usa AMP **y** CloudWatch para lo mismo, se paga dos veces. Decidir cual es la fuente de verdad por metrica.

---

## ✅ Definition of Done de la migracion

- [ ] `terraform apply` levanta toda la infra desde cero en <30 min.
- [ ] `https://pdsl.tudominio.dev/php/01..12/` responden 200 con la UI nativa.
- [ ] `https://pdsl.tudominio.dev/py/01..12/health` responden 200 (hub Python).
- [ ] `https://pdsl.tudominio.dev/node/01..12/health` responden 200 (hub Node).
- [ ] `https://pdsl.tudominio.dev/java/01..06/health` responden 200 (hub Java).
- [ ] Grafana publico (read-only) muestra latencia y QPS de caso 01 en vivo, mas `event_loop_lag_ms_p99` del hub Node.
- [ ] CI/CD: `git push main` → imagen en ECR → service redeploy automatico.
- [ ] AWS Budget con alerta activa a USD 50 y USD 150.
- [ ] WAF rate-based rules activas en `/php/*`, `/py/*`, `/node/*`.
- [ ] Cognito User Pool funcional para auth (resuelve hallazgo A1 del [`SECURITY.md`](SECURITY.md)).
- [ ] Documentado en este archivo el costo real del primer mes vs estimado.
- [ ] **Confirmar que cada hallazgo del [`SECURITY.md`](SECURITY.md) tiene su mitigacion AWS validada en produccion** (A1-A2 alta prioridad, M1-M4 verificado).
- [ ] El laboratorio sigue siendo evaluable en local con los 4 composes (`compose.root.yml`, `compose.python.yml`, `compose.nodejs.yml`, `compose.java.yml`) — no se rompe nada.

---

## 🔭 Roadmap post-migracion (opcional)

| Sprint | Entrega |
| --- | --- |
| +1 | Multi-AZ RDS + ALB sticky para caso 11 |
| +2 | Canary deploys con CodeDeploy en caso 06 |
| +3 | Chaos engineering con AWS FIS sobre caso 04 y 05 |
| +4 | Cost dashboard publico con AWS Cost Explorer API + Grafana |
| +5 | Migrar 1 caso a Lambda (caso 10) para comparar factura real Fargate vs Lambda |

---

## 🔗 Referencias

- [AWS Pricing Calculator](https://calculator.aws/)
- [ECS Fargate pricing](https://aws.amazon.com/fargate/pricing/)
- [RDS PostgreSQL pricing](https://aws.amazon.com/rds/postgresql/pricing/)
- [Amazon Managed Prometheus](https://aws.amazon.com/prometheus/)
- [Amazon Managed Grafana](https://aws.amazon.com/grafana/)
- [Well-Architected Framework](https://aws.amazon.com/architecture/well-architected/)
- [GitHub Actions OIDC con AWS](https://docs.github.com/en/actions/deployment/security-hardening-your-deployments/configuring-openid-connect-in-amazon-web-services)

---

> Este documento es un **plan**, no un estado. Mientras `infra/aws/` no exista en `main`, la migracion sigue en estado `PLANIFICADO` segun la taxonomia de madurez del repo (ver [README.md](README.md#-madurez-actual)).
