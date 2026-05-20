# dotnet-dispatcher — un solo contenedor con dispatcher + 6 casos (.NET 8).
#
# Espejo del patron java-dispatcher: stage build compila dispatcher y los 6
# Case0X.csproj por separado, stage runtime los junta en /app/{dispatcher,cases/0X}.

FROM mcr.microsoft.com/dotnet/sdk:8.0 AS build
WORKDIR /src

# Copiar cada csproj primero para aprovechar cache de restore
COPY dotnet-dispatcher/app/Dispatcher.csproj                                              /src/dispatcher/Dispatcher.csproj
COPY cases/01-api-latency-under-load/dotnet/app/Case01.csproj                             /src/case01/Case01.csproj
COPY cases/02-n-plus-one-and-db-bottlenecks/dotnet/app/Case02.csproj                      /src/case02/Case02.csproj
COPY cases/03-poor-observability-and-useless-logs/dotnet/app/Case03.csproj                /src/case03/Case03.csproj
COPY cases/04-timeout-chain-and-retry-storms/dotnet/app/Case04.csproj                     /src/case04/Case04.csproj
COPY cases/05-memory-pressure-and-resource-leaks/dotnet/app/Case05.csproj                 /src/case05/Case05.csproj
COPY cases/06-broken-pipeline-and-fragile-delivery/dotnet/app/Case06.csproj               /src/case06/Case06.csproj

# Copiar fuentes
COPY dotnet-dispatcher/app/Program.cs                                                     /src/dispatcher/Program.cs
COPY cases/01-api-latency-under-load/dotnet/app/Program.cs                                /src/case01/Program.cs
COPY cases/02-n-plus-one-and-db-bottlenecks/dotnet/app/Program.cs                         /src/case02/Program.cs
COPY cases/03-poor-observability-and-useless-logs/dotnet/app/Program.cs                   /src/case03/Program.cs
COPY cases/04-timeout-chain-and-retry-storms/dotnet/app/Program.cs                        /src/case04/Program.cs
COPY cases/05-memory-pressure-and-resource-leaks/dotnet/app/Program.cs                    /src/case05/Program.cs
COPY cases/06-broken-pipeline-and-fragile-delivery/dotnet/app/Program.cs                  /src/case06/Program.cs

RUN dotnet publish /src/dispatcher/Dispatcher.csproj -c Release --no-self-contained -o /publish/dispatcher \
 && dotnet publish /src/case01/Case01.csproj         -c Release --no-self-contained -o /publish/case01 \
 && dotnet publish /src/case02/Case02.csproj         -c Release --no-self-contained -o /publish/case02 \
 && dotnet publish /src/case03/Case03.csproj         -c Release --no-self-contained -o /publish/case03 \
 && dotnet publish /src/case04/Case04.csproj         -c Release --no-self-contained -o /publish/case04 \
 && dotnet publish /src/case05/Case05.csproj         -c Release --no-self-contained -o /publish/case05 \
 && dotnet publish /src/case06/Case06.csproj         -c Release --no-self-contained -o /publish/case06

FROM mcr.microsoft.com/dotnet/aspnet:8.0
WORKDIR /app

# wget para healthcheck del compose
RUN apt-get update && apt-get install -y --no-install-recommends wget \
 && rm -rf /var/lib/apt/lists/*

COPY --from=build /publish/dispatcher /app/dispatcher
COPY --from=build /publish/case01     /app/cases/01
COPY --from=build /publish/case02     /app/cases/02
COPY --from=build /publish/case03     /app/cases/03
COPY --from=build /publish/case04     /app/cases/04
COPY --from=build /publish/case05     /app/cases/05
COPY --from=build /publish/case06     /app/cases/06

EXPOSE 8500
ENV PORT=8500
ENV APP_STACK=".NET 8"

ENTRYPOINT ["dotnet", "/app/dispatcher/Dispatcher.dll"]
