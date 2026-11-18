<?php

namespace App\Models;

use App\Support\ContentTokens;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use \App\Models\Concerns\FlushesPageCache;

    /** Slugs servidos em /policies/{slug}, iguais às URLs da Shopify (mantém links e indexação). */
    public const POLICY_SLUGS = [
        'shipping-policy', 'refund-policy', 'privacy-policy',
        'terms-of-service', 'legal-notice', 'contact-information',
    ];

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    public function isPolicy(): bool { return in_array($this->slug, self::POLICY_SLUGS, true); }

    public function url(): string
    {
        return $this->isPolicy() ? route('policy.show', $this->slug) : route('page.show', $this->slug);
    }

    /** Conteúdo com [[variáveis]] já substituídas pelos dados do admin. */
    public function renderedContent(): string { return ContentTokens::render($this->content); }
}
