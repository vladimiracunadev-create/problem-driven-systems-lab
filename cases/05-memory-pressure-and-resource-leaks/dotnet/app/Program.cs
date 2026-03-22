var payload = new
{
    lab = "Problem-Driven Systems Lab",
    @case = "05 - Presión de memoria y fugas de recursos",
    stack = ".NET 8",
    message = "Base mínima dockerizada del caso.",
    focus = "El sistema consume memoria, descriptores o conexiones de forma progresiva hasta degradar o caerse."
};

var builder = WebApplication.CreateBuilder(args);
var app = builder.Build();

app.MapGet("/", () => Results.Json(payload));
app.Run();
