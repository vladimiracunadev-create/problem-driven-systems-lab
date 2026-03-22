var payload = new
{
    lab = "Problem-Driven Systems Lab",
    @case = "08 - Extracción de módulo crítico sin romper operación",
    stack = ".NET 8",
    message = "Base mínima dockerizada del caso.",
    focus = "Se necesita desacoplar una parte clave del sistema, pero esa parte participa en flujos sensibles y no admite quiebres."
};

var builder = WebApplication.CreateBuilder(args);
var app = builder.Build();

app.MapGet("/", () => Results.Json(payload));
app.Run();
