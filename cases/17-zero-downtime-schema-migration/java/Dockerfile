FROM eclipse-temurin:25-jdk-alpine
WORKDIR /app
COPY app/Main.java /app/Main.java
RUN javac Main.java
EXPOSE 8080
CMD ["java", "Main"]
