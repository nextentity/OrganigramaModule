<?php

declare(strict_types=1);

namespace App\Infrastructure\External\Jira;

use App\Infrastructure\External\HttpClientInterface;
use App\Infrastructure\External\NativeCurlHttpClient;
use RuntimeException;

/**
 * Adaptador para Jira Cloud REST API v3 con autenticación Basic (Email + API Token)
 * y vinculación bidireccional hacia GitHub Issues.
 */
final class JiraClient implements JiraClientInterface
{
    public function __construct(
        private readonly string $email = '',
        private readonly string $apiToken = '',
        private readonly string $jiraDomain = 'example.atlassian.net',
        private readonly string $projectKey = 'SUP',
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    public static function fromEnvironment(?HttpClientInterface $httpClient = null): self
    {
        return new self(
            email: (string) (getenv('JIRA_EMAIL') ?: ''),
            apiToken: (string) (getenv('JIRA_API_TOKEN') ?: ''),
            jiraDomain: (string) (getenv('JIRA_DOMAIN') ?: 'example.atlassian.net'),
            projectKey: (string) (getenv('JIRA_PROJECT_KEY') ?: 'SUP'),
            httpClient: $httpClient ?? new NativeCurlHttpClient(),
        );
    }

    public function isConfigured(): bool
    {
        return $this->email !== '' && $this->apiToken !== '' && $this->apiToken !== 'mock' && $this->apiToken !== 'test';
    }

    /**
     * {@inheritdoc}
     */
    public function createIssue(
        string $summary,
        string $description,
        string $issueType = 'Bug',
        ?string $githubIssueUrl = null,
    ): array {
        if (!$this->isConfigured()) {
            $mockNum = random_int(100, 999);
            $key = "{$this->projectKey}-{$mockNum}";
            return [
                'id'      => (string) ($mockNum * 100),
                'key'     => $key,
                'self'    => "https://{$this->jiraDomain}/rest/api/3/issue/{$mockNum}",
                'is_mock' => true,
            ];
        }

        $url = sprintf('https://%s/rest/api/3/issue', $this->jiraDomain);
        $client = $this->httpClient ?? new NativeCurlHttpClient();

        $payload = [
            'fields' => [
                'project'     => ['key' => $this->projectKey],
                'summary'     => $summary,
                'issuetype'   => ['name' => $issueType],
                'description' => $this->buildAdfDescription($description),
            ],
        ];

        $response = $client->post($url, $payload, $this->buildHeaders());

        if (!$response->isSuccessful()) {
            throw new RuntimeException(
                "Error al crear Jira Card ({$response->statusCode}): " . substr($response->body, 0, 300)
            );
        }

        $data = $response->json();
        $issueKey = (string) ($data['key'] ?? '');

        // Vincula el GitHub Issue como Remote Link en Jira si se especificó
        if ($githubIssueUrl !== null && $issueKey !== '') {
            $this->attachGitHubRemoteLink($client, $issueKey, $githubIssueUrl);
        }

        return [
            'id'   => (string) ($data['id'] ?? ''),
            'key'  => $issueKey,
            'self' => (string) ($data['self'] ?? ''),
        ];
    }

    private function attachGitHubRemoteLink(HttpClientInterface $client, string $issueKey, string $githubUrl): void
    {
        $linkUrl = sprintf('https://%s/rest/api/3/issue/%s/remotelink', $this->jiraDomain, urlencode($issueKey));

        $linkPayload = [
            'object' => [
                'url'   => $githubUrl,
                'title' => 'GitHub Issue Vinculado',
                'icon'  => [
                    'url16x16' => 'https://github.githubassets.com/favicons/favicon.png',
                    'title'    => 'GitHub',
                ],
            ],
        ];

        $client->post($linkUrl, $linkPayload, $this->buildHeaders());
    }

    /**
     * Construye un bloque de descripción compatible con Atlassian Document Format (ADF).
     *
     * @return array<string, mixed>
     */
    private function buildAdfDescription(string $text): array
    {
        $paragraphs = array_filter(preg_split('/\R+/', $text) ?: []);

        $content = [];
        foreach ($paragraphs as $para) {
            $content[] = [
                'type'    => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $para,
                    ],
                ],
            ];
        }

        if ($content === []) {
            $content[] = [
                'type'    => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ];
        }

        return [
            'type'    => 'doc',
            'version' => 1,
            'content' => $content,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $credentials = base64_encode("{$this->email}:{$this->apiToken}");

        return [
            'Authorization' => "Basic {$credentials}",
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];
    }
}

