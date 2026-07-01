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
