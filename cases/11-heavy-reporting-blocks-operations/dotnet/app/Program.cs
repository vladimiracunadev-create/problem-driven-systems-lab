var payload = new
{
    lab = "Problem-Driven Systems Lab",
    @case = "11 - Reportes pesados que bloquean la operación",
    stack = ".NET 8",
    message = "Base mínima dockerizada del caso.",
    focus = "Consultas y procesos de reporting compiten con la operación transaccional y degradan el sistema completo."
};

var builder = WebApplication.CreateBuilder(args);
var app = builder.Build();

app.MapGet("/", () => Results.Json(payload));
app.Run();
