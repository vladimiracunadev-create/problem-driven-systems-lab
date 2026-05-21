FROM eclipse-temurin:21-jdk-alpine
WORKDIR /app

# sqlite-jdbc para JDBC contra SQLite embebido (N+1 real).
RUN apk add --no-cache wget \
 && wget -q -O /app/sqlite-jdbc.jar \
    https://repo1.maven.org/maven2/org/xerial/sqlite-jdbc/3.46.1.3/sqlite-jdbc-3.46.1.3.jar

COPY app/Main.java /app/Main.java
RUN javac -cp /app/sqlite-jdbc.jar Main.java

EXPOSE 8080
CMD ["java", "-cp", "/app:/app/sqlite-jdbc.jar", "Main"]
