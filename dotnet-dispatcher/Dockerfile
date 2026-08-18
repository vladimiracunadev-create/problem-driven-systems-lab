# dotnet-dispatcher — un solo contenedor con dispatcher + los casos del lab (.NET 8).
#
# Espejo del patron java-dispatcher: stage build compila dispatcher y cada
# CaseNN.csproj por separado, stage runtime los junta en /app/{dispatcher,cases/NN}.

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
COPY cases/07-incremental-monolith-modernization/dotnet/app/Case07.csproj                 /src/case07/Case07.csproj
COPY cases/08-critical-module-extraction-without-breaking-operations/dotnet/app/Case08.csproj /src/case08/Case08.csproj
COPY cases/09-unstable-external-integration/dotnet/app/Case09.csproj                      /src/case09/Case09.csproj
COPY cases/10-expensive-architecture-for-simple-needs/dotnet/app/Case10.csproj            /src/case10/Case10.csproj
COPY cases/11-heavy-reporting-blocks-operations/dotnet/app/Case11.csproj                  /src/case11/Case11.csproj
COPY cases/12-single-point-of-knowledge-and-operational-risk/dotnet/app/Case12.csproj     /src/case12/Case12.csproj
COPY cases/13-cache-stampede-and-thundering-herd/dotnet/app/Case13.csproj        /src/case13/Case13.csproj
COPY cases/14-connection-pool-exhaustion/dotnet/app/Case14.csproj        /src/case14/Case14.csproj
COPY cases/15-message-queue-backpressure/dotnet/app/Case15.csproj        /src/case15/Case15.csproj
COPY cases/16-idempotency-and-duplicate-effects/dotnet/app/Case16.csproj        /src/case16/Case16.csproj
COPY cases/17-zero-downtime-schema-migration/dotnet/app/Case17.csproj        /src/case17/Case17.csproj
COPY cases/18-cold-start-and-autoscale-lag/dotnet/app/Case18.csproj        /src/case18/Case18.csproj

# Copiar fuentes
COPY dotnet-dispatcher/app/Program.cs                                                     /src/dispatcher/Program.cs
COPY cases/01-api-latency-under-load/dotnet/app/Program.cs                                /src/case01/Program.cs
COPY cases/02-n-plus-one-and-db-bottlenecks/dotnet/app/Program.cs                         /src/case02/Program.cs
COPY cases/03-poor-observability-and-useless-logs/dotnet/app/Program.cs                   /src/case03/Program.cs
COPY cases/04-timeout-chain-and-retry-storms/dotnet/app/Program.cs                        /src/case04/Program.cs
COPY cases/05-memory-pressure-and-resource-leaks/dotnet/app/Program.cs                    /src/case05/Program.cs
COPY cases/06-broken-pipeline-and-fragile-delivery/dotnet/app/Program.cs                  /src/case06/Program.cs
COPY cases/07-incremental-monolith-modernization/dotnet/app/Program.cs                    /src/case07/Program.cs
COPY cases/08-critical-module-extraction-without-breaking-operations/dotnet/app/Program.cs /src/case08/Program.cs
COPY cases/09-unstable-external-integration/dotnet/app/Program.cs                         /src/case09/Program.cs
COPY cases/10-expensive-architecture-for-simple-needs/dotnet/app/Program.cs               /src/case10/Program.cs
COPY cases/11-heavy-reporting-blocks-operations/dotnet/app/Program.cs                     /src/case11/Program.cs
COPY cases/12-single-point-of-knowledge-and-operational-risk/dotnet/app/Program.cs        /src/case12/Program.cs
COPY cases/13-cache-stampede-and-thundering-herd/dotnet/app/Program.cs           /src/case13/Program.cs
COPY cases/14-connection-pool-exhaustion/dotnet/app/Program.cs           /src/case14/Program.cs
COPY cases/15-message-queue-backpressure/dotnet/app/Program.cs           /src/case15/Program.cs
COPY cases/16-idempotency-and-duplicate-effects/dotnet/app/Program.cs           /src/case16/Program.cs
COPY cases/17-zero-downtime-schema-migration/dotnet/app/Program.cs           /src/case17/Program.cs
COPY cases/18-cold-start-and-autoscale-lag/dotnet/app/Program.cs           /src/case18/Program.cs

RUN dotnet publish /src/dispatcher/Dispatcher.csproj -c Release --no-self-contained -o /publish/dispatcher \
 && dotnet publish /src/case01/Case01.csproj         -c Release --no-self-contained -o /publish/case01 \
 && dotnet publish /src/case02/Case02.csproj         -c Release --no-self-contained -o /publish/case02 \
 && dotnet publish /src/case03/Case03.csproj         -c Release --no-self-contained -o /publish/case03 \
 && dotnet publish /src/case04/Case04.csproj         -c Release --no-self-contained -o /publish/case04 \
 && dotnet publish /src/case05/Case05.csproj         -c Release --no-self-contained -o /publish/case05 \
 && dotnet publish /src/case06/Case06.csproj         -c Release --no-self-contained -o /publish/case06 \
 && dotnet publish /src/case07/Case07.csproj         -c Release --no-self-contained -o /publish/case07 \
 && dotnet publish /src/case08/Case08.csproj         -c Release --no-self-contained -o /publish/case08 \
 && dotnet publish /src/case09/Case09.csproj         -c Release --no-self-contained -o /publish/case09 \
 && dotnet publish /src/case10/Case10.csproj         -c Release --no-self-contained -o /publish/case10 \
 && dotnet publish /src/case11/Case11.csproj         -c Release --no-self-contained -o /publish/case11 \
 && dotnet publish /src/case12/Case12.csproj         -c Release --no-self-contained -o /publish/case12 \
 && dotnet publish /src/case13/Case13.csproj         -c Release --no-self-contained -o /publish/case13 \
 && dotnet publish /src/case14/Case14.csproj         -c Release --no-self-contained -o /publish/case14 \
 && dotnet publish /src/case15/Case15.csproj         -c Release --no-self-contained -o /publish/case15 \
 && dotnet publish /src/case16/Case16.csproj         -c Release --no-self-contained -o /publish/case16 \
 && dotnet publish /src/case17/Case17.csproj         -c Release --no-self-contained -o /publish/case17 \
 && dotnet publish /src/case18/Case18.csproj         -c Release --no-self-contained -o /publish/case18

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
COPY --from=build /publish/case07     /app/cases/07
COPY --from=build /publish/case08     /app/cases/08
COPY --from=build /publish/case09     /app/cases/09
COPY --from=build /publish/case10     /app/cases/10
COPY --from=build /publish/case11     /app/cases/11
COPY --from=build /publish/case12     /app/cases/12
COPY --from=build /publish/case13     /app/cases/13
COPY --from=build /publish/case14     /app/cases/14
COPY --from=build /publish/case15     /app/cases/15
COPY --from=build /publish/case16     /app/cases/16
COPY --from=build /publish/case17     /app/cases/17
COPY --from=build /publish/case18     /app/cases/18

EXPOSE 8500
ENV PORT=8500
ENV APP_STACK=".NET 8"

ENTRYPOINT ["dotnet", "/app/dispatcher/Dispatcher.dll"]
