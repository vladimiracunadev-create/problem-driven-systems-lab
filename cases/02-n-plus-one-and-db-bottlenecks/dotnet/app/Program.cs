var payload = new
{
    lab = "Problem-Driven Systems Lab",
    @case = "02 - N+1 queries y cuellos de botella en base de datos",
    stack = ".NET 8",
    message = "Base mínima dockerizada del caso.",
    focus = "La aplicación ejecuta demasiadas consultas por solicitud o usa el ORM de forma ineficiente, generando saturación de base de datos."
};

var builder = WebApplication.CreateBuilder(args);
var app = builder.Build();

app.MapGet("/", () => Results.Json(payload));
app.Run();
