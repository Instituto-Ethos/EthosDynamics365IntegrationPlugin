<?php
/**
 * Minimal PSR-6 pool over wp_cache_* (C1 — replaces snicco/better-wp-cache).
 *
 * The AlexaCRM toolkit only uses getItem()/save() (token, metadata, cookie
 * jar), with values set through the item and expiry via expiresAt/expiresAfter.
 * Values are handed to wp_cache_* untouched: the persistent object cache
 * backend (Redis drop-in in production) serializes once — the former snicco
 * pool serialized a second time on top of it.
 *
 * Implements psr/cache 3.x interfaces (PHP 8+ typed signatures).
 */

namespace hacklabr;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

defined( 'ABSPATH' ) || exit;

class Ethos_WP_Cache_Item implements CacheItemInterface {

    private string $key;
    private mixed $value = null;
    private bool $hit = false;
    private ?int $expires_at = null;

    public function __construct( string $key ) {
        $this->key = $key;
    }

    public function getKey(): string {
        return $this->key;
    }

    public function get(): mixed {
        return $this->value;
    }

    public function isHit(): bool {
        return $this->hit;
    }

    public function set( mixed $value ): static {
        $this->value = $value;
        return $this;
    }

    public function expiresAt( ?\DateTimeInterface $expiration ): static {
        $this->expires_at = $expiration?->getTimestamp();
        return $this;
    }

    public function expiresAfter( int|\DateInterval|null $time ): static {
        if ( null === $time ) {
            $this->expires_at = null;
        } elseif ( $time instanceof \DateInterval ) {
            $this->expires_at = ( new \DateTimeImmutable() )->add( $time )->getTimestamp();
        } else {
            $this->expires_at = time() + $time;
        }

        return $this;
    }

    /**
     * Marks the item as a cache hit carrying a fetched value (pool-internal).
     */
    public function mark_as_hit( mixed $value ): void {
        $this->value = $value;
        $this->hit = true;
    }

    public function get_expires_at(): ?int {
        return $this->expires_at;
    }
}

class Ethos_WP_Cache_Pool implements CacheItemPoolInterface {

    private const GROUP = 'ethos_d365_psr6';

    /** @var array<string, CacheItemInterface> */
    private array $deferred = [];

    public function getItem( string $key ): CacheItemInterface {
        $item = new Ethos_WP_Cache_Item( $key );

        $found = wp_cache_get( $key, self::GROUP );

        if ( false !== $found ) {
            $item->mark_as_hit( $found );
        }

        return $item;
    }

    public function getItems( array $keys = [] ): iterable {
        $items = [];

        foreach ( $keys as $key ) {
            $items[ $key ] = $this->getItem( $key );
        }

        return $items;
    }

    public function hasItem( string $key ): bool {
        return $this->getItem( $key )->isHit();
    }

    public function clear(): bool {
        // Group-wide flush is not supported by wp_cache_*; the toolkit never calls it.
        return false;
    }

    public function deleteItem( string $key ): bool {
        return wp_cache_delete( $key, self::GROUP );
    }

    public function deleteItems( array $keys ): bool {
        $ok = true;

        foreach ( $keys as $key ) {
            $ok = $this->deleteItem( $key ) && $ok;
        }

        return $ok;
    }

    public function save( CacheItemInterface $item ): bool {
        if ( ! $item instanceof Ethos_WP_Cache_Item ) {
            return false;
        }

        $ttl = null === $item->get_expires_at()
            ? 0
            : max( 0, $item->get_expires_at() - time() );

        return wp_cache_set( $item->getKey(), $item->get(), self::GROUP, $ttl );
    }

    public function saveDeferred( CacheItemInterface $item ): bool {
        $this->deferred[ $item->getKey() ] = $item;
        return true;
    }

    public function commit(): bool {
        $ok = true;

        foreach ( $this->deferred as $item ) {
            $ok = $this->save( $item ) && $ok;
        }

        $this->deferred = [];

        return $ok;
    }
}
