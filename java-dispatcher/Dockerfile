FROM eclipse-temurin:21-jdk-alpine

WORKDIR /app

# Copiar cada Main.java de los 6 casos operativos en /cases/{id}/Main.java
COPY cases/01-api-latency-under-load/java/app/Main.java                   /cases/01/Main.java
COPY cases/02-n-plus-one-and-db-bottlenecks/java/app/Main.java            /cases/02/Main.java
COPY cases/03-poor-observability-and-useless-logs/java/app/Main.java      /cases/03/Main.java
COPY cases/04-timeout-chain-and-retry-storms/java/app/Main.java           /cases/04/Main.java
COPY cases/05-memory-pressure-and-resource-leaks/java/app/Main.java       /cases/05/Main.java
COPY cases/06-broken-pipeline-and-fragile-delivery/java/app/Main.java     /cases/06/Main.java

# Dispatcher
COPY java-dispatcher/app/Dispatcher.java /app/Dispatcher.java

# Compilar TODO en build-time (no en runtime — arranque mas rapido)
RUN javac /cases/01/Main.java /cases/02/Main.java /cases/03/Main.java \
          /cases/04/Main.java /cases/05/Main.java /cases/06/Main.java \
          /app/Dispatcher.java

EXPOSE 8400

CMD ["java", "-cp", "/app", "Dispatcher"]
