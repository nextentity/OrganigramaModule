<?php

declare(strict_types=1);

namespace App\Infrastructure\External\GitHub;

use App\Domain\Model\Ticket;
use App\Domain\Model\TriageResult;
use App\Infrastructure\External\HttpClientInterface;
use App\Infrastructure\External\NativeCurlHttpClient;
use RuntimeException;

/**
 * Adaptador de integración con GitHub REST API v3 / GitHub Actions.
 */
final class GitHubClient implements GitHubClientInterface
{
    public function __construct(
        private readonly string $token = '',
        private readonly string $owner = 'acme-corp',
        private readonly string $repo = 'organigrama-module',
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly string $apiBaseUrl = 'https://api.github.com',
    ) {
    }

    public static function fromEnvironment(?HttpClientInterface $httpClient = null): self
    {
        return new self(
            token: (string) (getenv('GITHUB_TOKEN') ?: ''),
            owner: (string) (getenv('GITHUB_OWNER') ?: 'nextentity'),
            repo: (string) (getenv('GITHUB_REPO') ?: 'OrganigramaModule'),
            httpClient: $httpClient ?? new NativeCurlHttpClient(),
            apiBaseUrl: (string) (getenv('GITHUB_API_BASE_URL') ?: 'https://api.github.com'),
        );
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->token !== 'mock' && $this->token !== 'test';
    }

    /**
     * {@inheritdoc}
     */
    public function createIssue(string $title, string $body, array $labels = []): array
    {
        if (!$this->isConfigured()) {
            $mockNumber = random_int(100, 999);
            return [
                'number'   => $mockNumber,
                'html_url' => "https://github.com/{$this->owner}/{$this->repo}/issues/{$mockNumber}",
                'id'       => $mockNumber * 10,
                'is_mock'  => true,
            ];
        }

        $url = sprintf('%s/repos/%s/%s/issues', rtrim($this->apiBaseUrl, '/'), $this->owner, $this->repo);
        $client = $this->httpClient ?? new NativeCurlHttpClient();

        $payload = [
            'title'  => $title,
            'body'   => $body,
            'labels' => $labels,
        ];

        $response = $client->post($url, $payload, $this->defaultHeaders());

        if (!$response->isSuccessful()) {
            throw new RuntimeException(
                "Error al crear GitHub Issue ({$response->statusCode}): " . substr($response->body, 0, 300)
            );
        }

        $data = $response->json();

        return [
            'number'   => (int) ($data['number'] ?? 0),
            'html_url' => (string) ($data['html_url'] ?? ''),
            'id'       => (int) ($data['id'] ?? 0),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getIssue(int $issueNumber): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $url = sprintf('%s/repos/%s/%s/issues/%d', rtrim($this->apiBaseUrl, '/'), $this->owner, $this->repo, $issueNumber);
        $client = $this->httpClient ?? new NativeCurlHttpClient();

        $response = $client->get($url, $this->defaultHeaders());

        if ($response->statusCode === 404) {
            return null;
        }

        if (!$response->isSuccessful()) {
            throw new RuntimeException(
                "Error al consultar GitHub Issue #{$issueNumber} ({$response->statusCode}): " . substr($response->body, 0, 300)
            );
        }

        $data = $response->json();

        return [
            'number'    => (int) ($data['number'] ?? $issueNumber),
            'state'     => (string) ($data['state'] ?? 'open'),
            'closed_at' => isset($data['closed_at']) ? (string) $data['closed_at'] : null,
            'html_url'  => (string) ($data['html_url'] ?? ''),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function triggerWorkflowDispatch(string $workflowFileName, string $ref, array $inputs): bool
    {
        if ($this->token === '' || $this->token === 'mock' || $this->token === 'test') {
            return true;
        }

        $url = sprintf(
            '%s/repos/%s/%s/actions/workflows/%s/dispatches',
            rtrim($this->apiBaseUrl, '/'),
            $this->owner,
            $this->repo,
            urlencode($workflowFileName)
        );

        $client = $this->httpClient ?? new NativeCurlHttpClient();

        $payload = [
            'ref'    => $ref,
            'inputs' => $inputs,
        ];

        $response = $client->post($url, $payload, $this->defaultHeaders());

        if ($response->statusCode !== 204 && !$response->isSuccessful()) {
            throw new RuntimeException(
                "Error al disparar workflow_dispatch de GitHub ({$response->statusCode}): " . substr($response->body, 0, 300)
            );
        }

        return true;
    }

    public function formatIssueBody(Ticket $ticket, TriageResult $triage): string
    {
        $reproList = $triage->reproSteps !== []
            ? implode("\n", array_map(static fn (string $step, int $i): string => sprintf('%d. %s', $i + 1, $step), $triage->reproSteps, array_keys($triage->reproSteps)))
            : '_No se detallaron pasos específicos._';

        $autoFixBadge = $triage->isAutoFixable
            ? '🤖 **Auto-Fix Habilitado** (se disparará workflow de Pull Request automatizado)'
            : '👨‍💻 **Requiere Intervención Manual de Ingeniería**';

        $patchSection = $triage->isAutoFixable
            ? <<<MD
### 🛠️ Indicaciones para Auto-Fix
- **Área Sugerida:** `{$triage->suggestedArea}`
- **Hints del Parche:** {$triage->patchHints}
MD
            : '';

        return <<<MARKDOWN
## 🐛 Reporte de Incidente Automático (AI Triage)

**Resumen:** {$triage->summary}  
**Severidad:** `{$triage->severity->value}` | **Tipo:** `{$triage->type->value}`  
**Estado de Auto-Reparación:** {$autoFixBadge}  

---

### 📋 Pasos para Reproducir
{$reproList}

{$patchSection}

---

### 🔍 Metadatos del Ticket
- **Ticket ID:** `{$ticket->id}`
- **Canal de Ingesta:** `{$ticket->sourceChannel->value}`
- **Cliente / Remitente:** `{$ticket->customerId}`
- **Fecha de Ingesta:** `{$ticket->createdAt->format('Y-m-d H:i:s T')}`

> _Generado automáticamente por el módulo de triaging de soporte técnico._
MARKDOWN;
    }

    /**
     * @return array<string, string>
     */
    private function defaultHeaders(): array
    {
        return [
            'Authorization'        => "Bearer {$this->token}",
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent'           => 'Organigrama-Support-Triage-Bot/1.0',
            'Content-Type'         => 'application/json',
        ];
    }
}

