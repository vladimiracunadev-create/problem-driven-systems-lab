var payload = new
{
    lab = "Problem-Driven Systems Lab",
    @case = "03 - Observabilidad deficiente y logs inútiles",
    stack = ".NET 8",
    message = "Base mínima dockerizada del caso.",
    focus = "Existen errores e incidentes, pero no hay trazabilidad suficiente para identificar causa raíz de forma rápida y confiable."
};

var builder = WebApplication.CreateBuilder(args);
var app = builder.Build();

app.MapGet("/", () => Results.Json(payload));
app.Run();
