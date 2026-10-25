FROM amazoncorretto:25.0.5-alpine3.24

# CVE-2026-91403: mitigacion oficial mientras el CSPU del proveedor se publica.
RUN set -eux; \
    for alias in "emsignrootcag1 [jdk]" "emsignrootcag2 [jdk]" "emsigneccrootcag3 [jdk]" "comodoaaaca [jdk]"; do \
      keytool -delete -cacerts -storepass changeit -alias "$alias" 2>/dev/null || true; \
    done; \
    for alias in "emsignrootcag1 [jdk]" "emsignrootcag2 [jdk]" "emsigneccrootcag3 [jdk]" "comodoaaaca [jdk]"; do \
      ! keytool -list -cacerts -storepass changeit -alias "$alias" >/dev/null 2>&1; \
    done
WORKDIR /app
COPY app/Main.java /app/Main.java
RUN javac Main.java
EXPOSE 8080
CMD ["java", "Main"]
