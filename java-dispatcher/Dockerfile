FROM eclipse-temurin:21-jdk-alpine

WORKDIR /app

# Copiar cada Main.java de los 12 casos operativos en /cases/{id}/Main.java
COPY cases/01-api-latency-under-load/java/app/Main.java                                  /cases/01/Main.java
COPY cases/02-n-plus-one-and-db-bottlenecks/java/app/Main.java                           /cases/02/Main.java
COPY cases/03-poor-observability-and-useless-logs/java/app/Main.java                     /cases/03/Main.java
COPY cases/04-timeout-chain-and-retry-storms/java/app/Main.java                          /cases/04/Main.java
COPY cases/05-memory-pressure-and-resource-leaks/java/app/Main.java                      /cases/05/Main.java
COPY cases/06-broken-pipeline-and-fragile-delivery/java/app/Main.java                    /cases/06/Main.java
COPY cases/07-incremental-monolith-modernization/java/app/Main.java                      /cases/07/Main.java
COPY cases/08-critical-module-extraction-without-breaking-operations/java/app/Main.java  /cases/08/Main.java
COPY cases/09-unstable-external-integration/java/app/Main.java                           /cases/09/Main.java
COPY cases/10-expensive-architecture-for-simple-needs/java/app/Main.java                 /cases/10/Main.java
COPY cases/11-heavy-reporting-blocks-operations/java/app/Main.java                       /cases/11/Main.java
COPY cases/12-single-point-of-knowledge-and-operational-risk/java/app/Main.java          /cases/12/Main.java

# Dispatcher
COPY java-dispatcher/app/Dispatcher.java /app/Dispatcher.java

# Compilar cada Main.java en su propio directorio (los 12 casos comparten
# la clase top-level `Main` — compilarlos juntos en un solo javac produce
# "duplicate class". Compilados por separado, cada Main.class queda en
# /cases/0X/ y el Dispatcher los carga con -cp /cases/0X).
RUN javac /cases/01/Main.java \
 && javac /cases/02/Main.java \
 && javac /cases/03/Main.java \
 && javac /cases/04/Main.java \
 && javac /cases/05/Main.java \
 && javac /cases/06/Main.java \
 && javac /cases/07/Main.java \
 && javac /cases/08/Main.java \
 && javac /cases/09/Main.java \
 && javac /cases/10/Main.java \
 && javac /cases/11/Main.java \
 && javac /cases/12/Main.java \
 && javac /app/Dispatcher.java

EXPOSE 8400

CMD ["java", "-cp", "/app", "Dispatcher"]
