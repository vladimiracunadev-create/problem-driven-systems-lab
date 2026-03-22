var payload = new
{
    lab = "Problem-Driven Systems Lab",
    @case = "10 - Arquitectura cara para un problema simple",
    stack = ".NET 8",
    message = "Base mínima dockerizada del caso.",
    focus = "La solución técnica consume más servicios, complejidad y costo del que el problema de negocio realmente necesita."
};

var builder = WebApplication.CreateBuilder(args);
var app = builder.Build();

app.MapGet("/", () => Results.Json(payload));
app.Run();
