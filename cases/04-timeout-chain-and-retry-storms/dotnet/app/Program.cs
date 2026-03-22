var payload = new
{
    lab = "Problem-Driven Systems Lab",
    @case = "04 - Cadena de timeouts y tormentas de reintentos",
    stack = ".NET 8",
    message = "Base mínima dockerizada del caso.",
    focus = "Una integración lenta o inestable dispara reintentos, bloqueos y cascadas de fallas entre servicios."
};

var builder = WebApplication.CreateBuilder(args);
var app = builder.Build();

app.MapGet("/", () => Results.Json(payload));
app.Run();
