<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use App\Http\Middleware\SignedPublicRead;
use App\Http\Requests\ArticleRequest;
use App\Http\Requests\DirectoryWriteRequest;
use App\Http\Requests\DiscoveryRequest;
use App\Http\Requests\IdentityRequest;
use App\Http\Requests\IntakeRequest;
use App\Http\Requests\OpportunityWriteRequest;
use App\Http\Requests\SiteSettingsRequest;
use App\Rules\SafeNavigationUrl;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Routes;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;

/** Route and validation declarations are the source of the generated contract. */
final class ApiContract
{
    /** @return array<string,mixed> */
    public function document(): array
    {
        $schemas = $this->schemas();
        $paths = [];
        foreach (Routes::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || $route->isFallback) {
                continue;
            }
            foreach (str_contains($route->uri(), '{kind}') ? ['builders', 'startups'] : [null] as $kind) {
                $uri = str_replace('{kind}', $kind ?? '{kind}', substr($route->uri(), 6));
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $action = $route->getActionMethod();
                    $controller = class_basename($route->getControllerClass());
                    $id = str_replace('Controller', '', $controller).'_'.$action.($kind ? '_'.$kind : '').'_'.$method;
                    $middleware = $route->gatherMiddleware();
                    $private = in_array('auth:sanctum', $middleware, true);
                    $signedPublic = in_array(SignedPublicRead::class, $middleware, true);
                    $parameters = [];
                    if ($signedPublic) {
                        $parameters[] = ['name' => 'X-Builder-SSR-Timestamp', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'pattern' => '^\\d{10}$'], 'description' => 'Server-only anonymous SSR mode: Unix timestamp within 30 seconds; use together with its HMAC signature, no cookies or Authorization. Omit both for normal browser reads.'];
                        $parameters[] = ['name' => 'X-Builder-SSR-Signature', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'], 'description' => 'HMAC-SHA256 using the private shared server key over timestamp + newline + GET + newline + exact path/query. Separate bounded service limiter; grants no account access. Invalid/stale signatures return 401.'];
                    }
                    preg_match_all('/\{([^}]+)\}/', $uri, $variables);
                    foreach ($variables[1] as $variable) {
                        $parameters[] = ['name' => $variable, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'URL slug, ULID or identifier as named by the route.'];
                    }
                    if (str_contains($uri, '/email/verify/')) {
                        foreach (['expires', 'signature'] as $name) {
                            $parameters[] = ['name' => $name, 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']];
                        }
                    }
                    $ifMatch = $controller === 'EditorialController' && in_array($action, ['update', 'transition', 'restore'], true);
                    if ($ifMatch) {
                        $parameters[] = ['name' => 'If-Match', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Current numeric article version. Missing: 428; stale: 412.'];
                    }
                    if (in_array($action, ['nominate', 'subscribe', 'submit'], true)) {
                        $parameters[] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]{8,120}$'], 'description' => 'Reuse the same key for retries of the same payload. Retained 24 hours. A changed payload returns 409.'];
                    }
                    $rules = $this->rules($route, $method, $kind);
                    $schemaName = $id.'Input';
                    $input = $this->inputSchema($rules);
                    if ($method === 'GET') {
                        foreach ($input['properties'] as $name => $schema) {
                            $parameters[] = ['name' => $name, 'in' => 'query', 'required' => false, 'schema' => $schema];
                        }
                    } elseif ($rules) {
                        $schemas[$schemaName] = $input;
                    }
                    $operation = ['operationId' => $id, 'summary' => ucwords(trim(preg_replace('/([A-Z])/', ' $1', $action))), 'tags' => [str_replace('Controller', '', $controller)], 'description' => $this->description($controller, $action), 'security' => $private ? [['SessionCookie' => []]] : [], 'parameters' => $parameters, 'x-laravel-action' => $route->getActionName(), 'x-middleware' => $middleware, 'x-frontend-route' => $this->frontend($controller, $action), 'x-side-effects' => $method === 'GET' ? 'Read only.' : $this->description($controller, $action), 'responses' => $this->responses($controller, $action, $method, $private)];
                    if ($method !== 'GET' && $rules) {
                        $operation['requestBody'] = ['required' => true, 'content' => [($controller === 'MediaController' && $action === 'store' ? 'multipart/form-data' : 'application/json') => ['schema' => ['$ref' => '#/components/schemas/'.$schemaName]]]];
                    }
                    if ($method !== 'GET') {
                        $operation['description'] .= ' Browser requests must first GET /sanctum/csrf-cookie with credentials, then send the decoded XSRF-TOKEN cookie as X-XSRF-TOKEN. Cookies are HttpOnly for the session; no bearer token is issued.';
                    }
                    $paths[$uri][strtolower($method)] = $operation;
                }
            }
        }
        ksort($paths);
        ksort($schemas);

        return ['openapi' => '3.0.3', 'info' => ['title' => 'The Builder API', 'version' => '1.0.0', 'description' => 'Clean Laravel 12 API. Proposed production API subdomain requires DNS and hosting verification. Health /up and Sanctum /sanctum/csrf-cookie are framework infrastructure, not legacy domain APIs. All dates are ISO 8601 UTC unless explicitly a calendar date. Editorial events return 404 while disabled.'], 'servers' => [['url' => 'http://localhost:8000/api/v1', 'description' => 'Local integration'], ['url' => 'https://api.thebuilderng.com/api/v1', 'description' => 'Proposed production origin; not deployed or verified']], 'paths' => $paths, 'components' => ['securitySchemes' => ['SessionCookie' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'the_builder_session', 'description' => 'Sanctum first-party session. Requires a configured stateful Origin sharing the frontend top-level domain.']], 'schemas' => $schemas]];
    }

    /** @return array<string,mixed> */
    private function rules(Route $route, string $method, ?string $kind): array
    {
        $controller = class_basename($route->getControllerClass());
        $action = $route->getActionMethod();
        $requestClass = match (true) {
            $controller === 'IdentityController' => IdentityRequest::class,
            $controller === 'EditorialController' && in_array($action, ['store', 'update'], true) => ArticleRequest::class,
            $controller === 'DirectoryController' && in_array($action, ['store', 'update'], true) => DirectoryWriteRequest::class,
            $controller === 'IntakeController' => IntakeRequest::class,
            $controller === 'OpportunityController' && $action === 'save' => OpportunityWriteRequest::class,
            $controller === 'AdministrationController' && $action === 'saveSettings' => SiteSettingsRequest::class,
            $controller === 'PublicController' && in_array($action, ['home', 'articles', 'builders', 'startups', 'opportunities', 'events', 'search', 'suggest', 'videos', 'categories'], true) => DiscoveryRequest::class,
            default => null,
        };
        if ($requestClass) {
            $request = new $requestClass;
            $request->setMethod($method);
            $copy = clone $route;
            $copy->bind($request);
            if ($kind) {
                $copy->setParameter('kind', $kind);
            }
            $request->setRouteResolver(fn () => $copy);

            return $request->rules();
        }
        // Inline validation uses literal declarations; extracting only validate([...])
        // prevents response-field values from being mistaken for validation rules.
        $reflection = new \ReflectionMethod($route->getControllerClass(), $action);
        $source = implode('', array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
        if (! preg_match('/->validate\(\[(.*?)\]\);/s', $source, $match)) {
            return [];
        }
        preg_match_all("/'([a-zA-Z][a-zA-Z0-9_.*]*)'\s*=>\s*'([^']*)'/", $match[1], $rules, PREG_SET_ORDER);

        return array_column($rules, 2, 1);
    }

    /** @param array<string,mixed> $rules
     * @return array<string,mixed> */
    private function inputSchema(array $rules): array
    {
        $result = ['type' => 'object', 'properties' => []];
        foreach ($rules as $name => $declaration) {
            $parts = [];
            foreach (is_array($declaration) ? $declaration : [$declaration] as $rule) {
                if (is_string($rule)) {
                    $parts = array_merge($parts, explode('|', $rule));
                } elseif ($rule instanceof Password) {
                    $parts = array_merge($parts, ['string', 'min:8', 'mixedCase', 'numbers']);
                } elseif ($rule instanceof SafeNavigationUrl) {
                    $parts[] = 'safe_navigation_url';
                } elseif (is_object($rule) && method_exists($rule, '__toString')) {
                    $parts[] = (string) $rule;
                }
            }
            $type = match (true) {
                count(array_filter($parts, fn ($part) => str_starts_with($part, 'array:'))) > 0 => 'object',in_array('array', $parts, true) => 'array',in_array('integer', $parts, true) => 'integer',in_array('boolean', $parts, true) || in_array('accepted', $parts, true) => 'boolean',default => 'string'
            };
            if (in_array($name, ['privacy', 'socialLinks', 'social_links'], true)) {
                $type = 'object';
            }
            $field = ['type' => $type, 'x-laravel-validation' => implode('|', $parts)];
            if ($name === 'bodyDocument') {
                $field['description'] = 'Version 1 bounded rich-text document (doc/content). Supports paragraphs, H2-H4, lists, quotes, tables, code, dividers and mediaFigure nodes containing library mediaId, alt, caption, credit and layout. Text marks support fonts, sizes, hex colours, links and emphasis. Maximum 500 KB, 5000 nodes and 24 nesting levels. Do not submit body with bodyDocument.';
            }
            if (in_array('safe_navigation_url', $parts, true)) {
                $field['description'] = 'HTTP(S) URL without embedded credentials or a site path beginning with one slash. Backslashes, whitespace/control characters and protocol-relative URLs are rejected.';
            }
            if ($type === 'array') {
                $field['items'] = ['type' => 'string'];
                if ($name === 'bodyDocument.content') {
                    $field['items'] = ['type' => 'object', 'additionalProperties' => true];
                }
            }
            if (in_array('nullable', $parts, true)) {
                $field['nullable'] = true;
            }
            if (in_array('email:rfc', $parts, true)) {
                $field['format'] = 'email';
            }
            if (in_array('date', $parts, true)) {
                $field['description'] = 'ISO date or date-time accepted by Laravel; returned in UTC.';
            }
            if (in_array('image', $parts, true)) {
                $field = ['type' => 'string', 'format' => 'binary', 'description' => 'JPEG, PNG or WebP, <=10 MB and <=8 megapixels. Re-encoded as WebP.'];
            }
            foreach ($parts as $part) {
                if (str_starts_with($part, 'in:')) {
                    $field['enum'] = explode(',', substr($part, 3));
                }
                if (preg_match('/^(min|max):(\d+)$/', $part, $limit)) {
                    $field[$type === 'array' ? ($limit[1] === 'min' ? 'minItems' : 'maxItems') : ($type === 'integer' ? ($limit[1] === 'min' ? 'minimum' : 'maximum') : ($limit[1] === 'min' ? 'minLength' : 'maxLength'))] = (int) $limit[2];
                }
            }
            $segments = explode('.', $name);
            $target = &$result;
            foreach ($segments as $index => $segment) {
                $last = $index === count($segments) - 1;
                if ($segment === '*') {
                    if ($last) {
                        $target[($target['type'] ?? null) === 'object' ? 'additionalProperties' : 'items'] = $field;
                        break;
                    }
                    if (($target['items']['type'] ?? null) !== 'object') {
                        $target['items'] = ['type' => 'object', 'properties' => []];
                    }$target = &$target['items'];
                } else {
                    if ($last) {
                        $target['properties'][$segment] = $field;
                        if (in_array('required', $parts, true)) {
                            $target['required'][] = $segment;
                        }break;
                    }
                    if (! isset($target['properties'][$segment])) {
                        $target['properties'][$segment] = ['type' => $segments[$index + 1] === '*' ? 'array' : 'object'];
                    }
                    if (($target['properties'][$segment]['type'] ?? null) === 'string') {
                        $target['properties'][$segment]['type'] = 'object';
                    }
                    $target = &$target['properties'][$segment];
                }
            }
            unset($target);
        }
        // Keep full nested validation rules machine-readable as well as the top-level schema.
        $result['x-validation-fields'] = array_map(fn ($rules) => is_array($rules) ? array_map(fn ($rule) => is_object($rule) ? get_class($rule) : $rule, $rules) : $rules, $rules);

        return $result;
    }

    /** @return array<int,mixed> */
    private function responses(string $controller, string $action, string $method, bool $private): array
    {
        $result = [];
        $empty = $method === 'DELETE' || in_array($action, ['logout', 'resetPassword', 'changePassword', 'view', 'remove', 'track', 'adEvent', 'revokeSession', 'challenge', 'unsubscribe', 'forgotPassword', 'resend', 'subscribe', 'verify'], true) || ($controller === 'ReaderController' && $action === 'save');
        $status = match (true) {
            $action === 'verify' => '302',in_array($action, ['forgotPassword', 'resend', 'sync', 'subscribe'], true) => '202',$empty => '204',in_array($action, ['register', 'store', 'nominate', 'submit', 'draftNomination'], true) => '201',$controller === 'OpportunityController' && $action === 'save' && $method === 'POST' => '201',default => '200'
        };
        $dataSchema = match ($controller) {
            'IdentityController' => in_array($action, ['register', 'login', 'me', 'onboarding'], true) ? 'User' : 'Document','EditorialController' => 'Article','PublicController' => match ($action) {
                'article','articles' => 'Article','builder','builders','startup','startups' => 'Directory','video','videos' => 'Video','opportunity','opportunities' => 'Opportunity',default => 'Document'
            },
            'ReaderController' => in_array($action, ['bookmarks', 'recent', 'feed'], true) ? 'Article' : 'Document','ReviewController' => $action === 'draftNomination' ? 'Article' : 'Document','DirectoryController' => 'Directory','OpportunityController' => 'Opportunity','MfaController' => 'Document',default => 'Document',
        };
        $collection = in_array($action, ['articles', 'builders', 'startups', 'opportunities', 'videos', 'bookmarks', 'recent', 'feed', 'index', 'users', 'audit', 'history', 'campaigns', 'nominations', 'submissions', 'versions', 'categories', 'tags', 'channels', 'roles', 'sessions', 'homepage', 'events', 'suggest'], true);
        $schema = ['type' => 'object', 'properties' => ['data' => $collection ? ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/'.$dataSchema]] : ['$ref' => '#/components/schemas/'.$dataSchema], 'meta' => ['$ref' => '#/components/schemas/PaginationMeta'], 'links' => ['type' => 'object', 'additionalProperties' => true]], 'required' => ['data'], 'additionalProperties' => true];
        $result[$status] = ['description' => $empty ? 'Success with no response body.' : 'Success. Collections use Laravel data/links/meta or native paginator fields.', 'headers' => ['X-Request-ID' => ['schema' => ['type' => 'string']]]];
        if (! $empty) {
            $result[$status]['content'] = ['application/json' => ['schema' => $schema]];
        }
        if ($action === 'draftNomination') {
            $result['200'] = $result['201'];
        }
        if ($controller === 'MetricsController' && $action === 'ad') {
            $result[$status]['content']['application/json']['schema']['properties']['data'] = ['type' => 'object', 'nullable' => true, 'additionalProperties' => true];
        }
        if ($action === 'sitemap') {
            $result['200']['content'] = ['application/xml' => ['schema' => ['type' => 'string']]];
        }
        foreach (array_unique(array_merge([400, 404, 409, 422, 429, 500, 503], $private ? [401, 403] : [], $controller === 'PublicController' || ($controller === 'MetricsController' && $action === 'ad') ? [401] : [], $method !== 'GET' ? [419] : [], $controller === 'EditorialController' ? [412, 428] : [])) as $error) {
            $result[(string) $error] = ['description' => Response::$statusTexts[$error] ?? 'Request failed', 'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]];
        }
        if ($controller === 'IdentityController' && $action === 'verify') {
            $result['302'] = ['description' => 'Verified; browser redirected to frontend sign-in.'];
        }
        if ($controller === 'PublicController' && $action === 'article') {
            $result['301'] = ['description' => 'A moved article returns data.redirect with a /stories/... destination.'];
        }

        return $result;
    }

    private function description(string $controller, string $action): string
    {
        return match ($controller.'.'.$action) {
            'EditorialController.index' => 'Searches titles and author names, then applies exact workflow status and edition filters before pagination. Editors without content.publish permission only list their own stories. Ordered by newest creation time, with ID as a stable tie-breaker. Edition both matches records explicitly assigned both.',
            'EditorialController.transition' => 'Enforces draft/review/changes/approved/scheduled/published/rejected/archived state transitions, permissions, notes and optimistic version checks. Saves a revision and immutable audit event.',
            'EditorialController.store','EditorialController.update' => 'Validates approved article fields, sanitizes rich HTML, synchronizes approved relations, increments version and saves a revision. Published slug changes create redirects.',
            'IdentityController.deleteAccount' => 'Requires the current password, deactivates the account and revokes sessions. Daily purge after 30 days removes reader data and anonymizes retained editorial attribution.',
            'IntakeController.nominate','IntakeController.submit' => 'Creates one reference under a transaction, persists an intake record, and queues acknowledgment mail after commit. Idempotent retries return the original response.',
            'IntakeController.subscribe' => 'Queues a double-opt-in email. Stores only a hash of the expiring token. Production intake requires verified Turnstile.',
            'YoutubeController.sync' => 'Queues bounded official-channel synchronization. Requires a private server API key; provider metadata never overwrites editorial title or description.',
            'PublicController.events' => 'Optional editorial listing only. Returns 404 while the launch feature flag is disabled; no event registration or ticketing.',
            default => 'See the declared validation rules, required middleware, response resource and frontend route. Mutations persist to the relational database; privileged mutations record audit events.',
        };
    }

    private function frontend(string $controller, string $action): string
    {
        return match ($controller) {
            'IdentityController' => '/signin, /register, /verify-email, /forgot-password, /reset-password, /account, /onboarding',
            'MfaController' => '/mfa, /mfa-setup','EditorialController' => '/console/content, /console/editor','DirectoryController' => '/console/builders, /console/startups','OpportunityController' => '/console/opportunities','IntakeController' => '/nominate, /newsletter, /account?state=submit','ReaderController' => '/account, /stories/[slug]','YoutubeController' => '/console/settings, /console/videos','ReviewController' => '/console/nominations, /console/submissions','PublicController' => '/, /stories, /stories/[slug], /category/[slug], /builders, /startups, /diaspora, /search, /videos, /opportunities','MediaController' => '/console/media',default => '/console',
        };
    }

    /** @return array<string,mixed> */
    private function schemas(): array
    {
        $object = fn (array $properties, array $required = []) => ['type' => 'object', 'properties' => $properties, 'additionalProperties' => true] + ($required ? ['required' => $required] : []);
        $string = ['type' => 'string'];
        $nullable = ['type' => 'string', 'nullable' => true];
        $integer = ['type' => 'integer'];
        $boolean = ['type' => 'boolean'];

        return [
            'Problem' => $object(['type' => $string, 'title' => $string, 'status' => $integer, 'detail' => $string, 'message' => $string, 'requestId' => $nullable, 'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => $string]]], ['type', 'title', 'status', 'detail']),
            'PaginationMeta' => $object(['current_page' => $integer, 'last_page' => $integer, 'per_page' => $integer, 'total' => $integer, 'from' => ['type' => 'integer', 'nullable' => true], 'to' => ['type' => 'integer', 'nullable' => true]], ['current_page', 'last_page', 'per_page', 'total']),
            'Document' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Endpoint-specific relational record or dashboard document. Validation declarations and resource classes define its allowed mutation fields.'],
            'User' => $object(['id' => $string, 'name' => $string, 'email' => ['type' => 'string', 'format' => 'email'], 'emailVerified' => $boolean, 'onboardingComplete' => $boolean, 'roles' => ['type' => 'array', 'items' => $string], 'permissions' => ['type' => 'array', 'items' => $string], 'mfaRequired' => $boolean, 'mfaEnabled' => $boolean], ['id', 'name', 'email']),
            'Article' => $object(['id' => $string, 'slug' => $string, 'title' => $string, 'summary' => $string, 'body' => $nullable, 'bodySchemaVersion' => $integer, 'bodyDocument' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => true, 'description' => 'Version 1 rich document; returned in console responses. Image delivery URLs are response-only.'], 'type' => $string, 'author' => $string, 'authorId' => $string, 'kicker' => $string, 'category' => $nullable, 'categorySlug' => $nullable, 'imageUrl' => $nullable, 'imageAlt' => $nullable, 'imageCredit' => $nullable, 'edition' => $string, 'readTime' => $string, 'publishedAt' => $nullable, 'isSponsored' => $boolean, 'tags' => ['type' => 'array', 'items' => $object(['id' => $string, 'name' => $string, 'slug' => $string])], 'seo' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => true], 'status' => $string, 'version' => $integer], ['id', 'slug', 'title', 'summary']),
            'Directory' => $object(['id' => $string, 'slug' => $string, 'name' => $string, 'country' => $string, 'industry' => $string, 'classification' => $string, 'headline' => $string, 'bio' => $nullable, 'location' => $string, 'website' => $nullable, 'oneLiner' => $string, 'hq' => $string, 'stage' => $string, 'description' => $nullable, 'imageUrl' => $nullable, 'imageAlt' => $nullable, 'imageCredit' => $nullable], ['id', 'slug', 'name']),
            'Opportunity' => $object(['id' => $string, 'slug' => $string, 'title' => $string, 'organisation' => $string, 'type' => $string, 'location' => $string, 'deadline' => $nullable, 'daysLeft' => ['type' => 'integer', 'nullable' => true], 'description' => $string, 'eligibility' => $nullable, 'applyUrl' => $string], ['id', 'title', 'organisation']),
            'Video' => $object(['id' => $string, 'youtubeId' => $string, 'title' => $string, 'description' => $nullable, 'thumbnailUrl' => $nullable, 'embedUrl' => $string, 'url' => $string, 'duration' => $string, 'format' => ['type' => 'string', 'nullable' => true, 'enum' => ['interview', 'podcast', 'documentary']], 'date' => $nullable], ['id', 'title', 'embedUrl']),
        ];
    }
}
