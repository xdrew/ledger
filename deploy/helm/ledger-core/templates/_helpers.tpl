{{- define "ledger-core.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "ledger-core.fullname" -}}
{{- printf "%s-%s" .Release.Name (include "ledger-core.name" .) | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "ledger-core.labels" -}}
app.kubernetes.io/name: {{ include "ledger-core.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
helm.sh/chart: {{ printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" }}
{{- end -}}

{{- define "ledger-core.selectorLabels" -}}
app.kubernetes.io/name: {{ include "ledger-core.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end -}}

{{/* Common env: non-secret config from the ConfigMap, secrets from the Secret. */}}
{{- define "ledger-core.envFrom" -}}
- configMapRef:
    name: {{ include "ledger-core.fullname" . }}-config
- secretRef:
    name: {{ include "ledger-core.fullname" . }}-secret
{{- end -}}

{{/*
Shared data blocks. The release ConfigMap/Secret and the hook-scoped copies for
the migration Job (migrate-env.yaml) both render from these so they never drift.
*/}}
{{- define "ledger-core.configData" -}}
APP_ENV: {{ .Values.config.appEnv | quote }}
APP_DEBUG: "0"
LOG_LEVEL: {{ .Values.config.logLevel | quote }}
IDEMPOTENCY_TTL_SECONDS: {{ .Values.config.idempotencyTtlSeconds | quote }}
READINESS_RELAY_MAX_AGE: {{ .Values.config.readinessRelayMaxAge | quote }}
OTEL_TRACING_ENABLED: {{ .Values.config.otelTracingEnabled | quote }}
{{- end -}}

{{- define "ledger-core.secretData" -}}
DATABASE_URL: {{ .Values.secret.databaseUrl | quote }}
API_KEY: {{ .Values.secret.apiKey | quote }}
APP_SECRET: {{ .Values.secret.appSecret | quote }}
{{- end -}}
