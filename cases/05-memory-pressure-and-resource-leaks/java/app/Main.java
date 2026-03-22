import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;
import java.io.IOException;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.nio.charset.StandardCharsets;

public class Main {
    public static void main(String[] args) throws Exception {
        HttpServer server = HttpServer.create(new InetSocketAddress(8080), 0);
        server.createContext("/", Main::handle);
        server.start();
        System.out.println("Servidor Java escuchando en 8080");
    }

    private static void handle(HttpExchange exchange) throws IOException {
        String body = """{
  "lab": "Problem-Driven Systems Lab",
  "case": "05 - Presión de memoria y fugas de recursos",
  "stack": "Java",
  "message": "Base mínima dockerizada del caso.",
  "focus": "El sistema consume memoria, descriptores o conexiones de forma progresiva hasta degradar o caerse."
}""";
        exchange.getResponseHeaders().add("Content-Type", "application/json; charset=utf-8");
        byte[] bytes = body.getBytes(StandardCharsets.UTF_8);
        exchange.sendResponseHeaders(200, bytes.length);
        try (OutputStream os = exchange.getResponseBody()) {
            os.write(bytes);
        }
    }
}
