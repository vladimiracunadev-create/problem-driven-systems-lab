var payload = new
{
    lab = "Problem-Driven Systems Lab",
    @case = "07 - Modernización incremental de monolito",
    stack = ".NET 8",
    message = "Base mínima dockerizada del caso.",
    focus = "El sistema legacy sigue siendo crítico, pero su evolución se vuelve lenta, riesgosa y costosa."
};

var builder = WebApplication.CreateBuilder(args);
var app = builder.Build();

app.MapGet("/", () => Results.Json(payload));
app.Run();
