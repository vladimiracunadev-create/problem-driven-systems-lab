var payload = new
{
    lab = "Problem-Driven Systems Lab",
    @case = "06 - Pipeline roto y entrega frágil",
    stack = ".NET 8",
    message = "Base mínima dockerizada del caso.",
    focus = "El software funciona en desarrollo, pero falla al desplegar, promover cambios o revertir incidentes con seguridad."
};

var builder = WebApplication.CreateBuilder(args);
var app = builder.Build();

app.MapGet("/", () => Results.Json(payload));
app.Run();
