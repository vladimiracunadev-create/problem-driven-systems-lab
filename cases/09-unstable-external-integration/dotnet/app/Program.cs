var payload = new
{
    lab = "Problem-Driven Systems Lab",
    @case = "09 - Integración externa inestable",
    stack = ".NET 8",
    message = "Base mínima dockerizada del caso.",
    focus = "Una API, servicio o proveedor externo introduce latencia, errores intermitentes o reglas cambiantes que afectan el sistema propio."
};

var builder = WebApplication.CreateBuilder(args);
var app = builder.Build();

app.MapGet("/", () => Results.Json(payload));
app.Run();
