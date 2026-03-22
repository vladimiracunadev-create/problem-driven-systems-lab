var payload = new
{
    lab = "Problem-Driven Systems Lab",
    @case = "12 - Punto único de conocimiento y riesgo operacional",
    stack = ".NET 8",
    message = "Base mínima dockerizada del caso.",
    focus = "Una persona, módulo o procedimiento concentra tanto conocimiento que el sistema se vuelve frágil ante ausencias o rotación."
};

var builder = WebApplication.CreateBuilder(args);
var app = builder.Build();

app.MapGet("/", () => Results.Json(payload));
app.Run();
