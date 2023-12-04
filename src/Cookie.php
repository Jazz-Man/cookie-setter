<?php

namespace JazzMan\Cookie;

use DateTimeInterface;
use InvalidArgumentException;
use JetBrains\PhpStorm\ExpectedValues;

class Cookie {

    public const SAMESITE_NONE = 'none';
    public const SAMESITE_LAX = 'lax';
    public const SAMESITE_STRICT = 'strict';

    private const SAMESITE_MAP = [
        self::SAMESITE_LAX,
        self::SAMESITE_STRICT,
        self::SAMESITE_NONE,
        '',
        null,
    ];

    private const RESERVED_CHARS_LIST = "=,; \t\r\n\v\f";
    private const RESERVED_CHARS_FROM = ['=', ',', ';', ' ', "\t", "\r", "\n", "\v", "\f"];
    private const RESERVED_CHARS_TO = ['%3D', '%2C', '%3B', '%20', '%09', '%0D', '%0A', '%0B', '%0C'];

    protected string $name;

    protected int $expire;
    protected string $path;

    private ?string $sameSite = null;
    private bool $secureDefault = false;

    /**
     * @param string                       $name     The name of the cookie
     * @param string|null                  $value    The value of the cookie
     * @param int|string|DateTimeInterface $expire   The time the cookie expires
     * @param string|null                  $path     The path on the server in which the cookie will be available on
     * @param string|null                  $domain   The domain that the cookie is available to
     * @param bool|null                    $secure   Whether the client should send back the cookie only over HTTPS or null to auto-enable this when the request is already using HTTPS
     * @param bool                         $httpOnly Whether the cookie will be made accessible only through the HTTP protocol
     * @param bool                         $raw      Whether the cookie value should be sent with no url encoding
     * @param string|null                  $sameSite Whether the cookie will be available for cross-site requests
     */
    public function __construct(
        string $name,
        protected ?string $value = null,
        DateTimeInterface|int|string $expire = 0,
        ?string $path = '/',
        protected ?string $domain = null,
        protected ?bool $secure = null,
        protected bool $httpOnly = true,
        private bool $raw = false,
        #[ExpectedValues( values: self::SAMESITE_MAP )]
        ?string $sameSite = self::SAMESITE_LAX,
        private bool $partitioned = false
    ) {
        // from PHP source code
        if ( $raw && false !== strpbrk( $name, self::RESERVED_CHARS_LIST ) ) {
            throw new InvalidArgumentException( sprintf( 'The cookie name "%s" contains invalid characters.', $name ) );
        }

        if ( empty( $name ) ) {
            throw new InvalidArgumentException( 'The cookie name cannot be empty.' );
        }

        $this->name = $name;

        $this->expire = self::expiresTimestamp( $expire );
        $this->path = empty( $path ) ? '/' : $path;
        $this->sameSite = $this->withSameSite( $sameSite )->sameSite;
    }

    /**
     * Returns the cookie as a string.
     */
    public function __toString(): string {
        if ( $this->isRaw() ) {
            $str = $this->getName();
        } else {
            $str = str_replace( self::RESERVED_CHARS_FROM, self::RESERVED_CHARS_TO, $this->getName() );
        }

        $str .= '=';

        if ( '' === (string) $this->getValue() ) {
            $str .= sprintf( 'deleted; expires=%s; Max-Age=0', gmdate( 'D, d M Y H:i:s T', time() - 31536001 ) );

        } else {
            $str .= $this->isRaw() ? $this->getValue() : rawurlencode( $this->getValue() );

            if ( 0 !== $this->getExpiresTime() ) {
                $str .= '; expires='.gmdate( 'D, d M Y H:i:s T', $this->getExpiresTime() ).'; Max-Age='.$this->getMaxAge();
            }
        }

        if ( $this->getPath() ) {
            $str .= '; path='.$this->getPath();
        }

        if ( $this->getDomain() ) {
            $str .= '; domain='.$this->getDomain();
        }

        if ( $this->isSecure() ) {
            $str .= '; secure';
        }

        if ( $this->isHttpOnly() ) {
            $str .= '; httponly';
        }

        if ( null !== $this->getSameSite() ) {
            $str .= '; samesite='.$this->getSameSite();
        }

        if ( $this->isPartitioned() ) {
            $str .= '; partitioned';
        }

        return $str;
    }

    /**
     * Creates a cookie copy with SameSite attribute.
     *
     * @param string|null $sameSite
     *
     * @return Cookie
     */
    public function withSameSite( #[ExpectedValues( values: self::SAMESITE_MAP )] ?string $sameSite ): static {
        if ( '' === $sameSite ) {
            $sameSite = null;
        } elseif ( null !== $sameSite ) {
            $sameSite = strtolower( $sameSite );
        }

        if ( ! \in_array( $sameSite, self::SAMESITE_MAP, true ) ) {
            throw new InvalidArgumentException( 'The "sameSite" parameter value is not valid.' );
        }

        $cookie = clone $this;
        $cookie->sameSite = $sameSite;

        return $cookie;
    }

    /**
     * Creates cookie from raw header string.
     */
    public static function fromString( string $cookie, bool $decode = false ): static {
        $data = [
            'expires' => 0,
            'path' => '/',
            'domain' => null,
            'secure' => false,
            'httponly' => false,
            'raw' => ! $decode,
            'samesite' => null,
            'partitioned' => false,
        ];

        $parts = Utils::split( $cookie, ';=' );
        $part = array_shift( $parts );

        $name = $decode ? urldecode( $part[0] ) : $part[0];
        $value = isset( $part[1] ) ? ( $decode ? urldecode( $part[1] ) : $part[1] ) : null;

        $data = Utils::combine( $parts ) + $data;
        $data['expires'] = self::expiresTimestamp( $data['expires'] );

        if ( isset( $data['max-age'] ) && ( 0 < $data['max-age'] || time() < $data['expires'] ) ) {
            $data['expires'] = time() + (int) $data['max-age'];
        }

        return new static( $name, $value, $data['expires'], $data['path'], $data['domain'], $data['secure'], $data['httponly'], $data['raw'], $data['samesite'], $data['partitioned'] );
    }

    /**
     * @see self::__construct
     */
    public static function create(
        string $name,
        ?string $value = null,
        DateTimeInterface|int|string $expire = 0,
        ?string $path = '/',
        ?string $domain = null,
        ?bool $secure = null,
        bool $httpOnly = true,
        bool $raw = false,
        #[ExpectedValues( values: self::SAMESITE_MAP )]
        ?string $sameSite = self::SAMESITE_LAX,
        bool $partitioned = false
    ): self {
        return new self( $name, $value, $expire, $path, $domain, $secure, $httpOnly, $raw, $sameSite, $partitioned );
    }

    /**
     * Checks if the cookie value should be sent with no url encoding.
     */
    public function isRaw(): bool {
        return $this->raw;
    }

    /**
     * Gets the name of the cookie.
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Gets the value of the cookie.
     */
    public function getValue(): ?string {
        return $this->value;
    }

    /**
     * Gets the time the cookie expires.
     */
    public function getExpiresTime(): int {
        return $this->expire;
    }

    /**
     * Gets the max-age attribute.
     */
    public function getMaxAge(): int {
        $maxAge = $this->expire - time();

        return max(0, $maxAge);
    }

    /**
     * Gets the path on the server in which the cookie will be available on.
     */
    public function getPath(): string {
        return $this->path;
    }

    /**
     * Gets the domain that the cookie is available to.
     */
    public function getDomain(): ?string {
        return $this->domain;
    }

    /**
     * Checks whether the cookie should only be transmitted over a secure HTTPS connection from the client.
     */
    public function isSecure(): bool {
        return $this->secure ?? $this->secureDefault;
    }

    /**
     * Checks whether the cookie will be made accessible only through the HTTP protocol.
     */
    public function isHttpOnly(): bool {
        return $this->httpOnly;
    }

    public function getSameSite(): ?string {
        return $this->sameSite;
    }

    /**
     * Checks whether the cookie should be tied to the top-level site in cross-site context.
     */
    public function isPartitioned(): bool {
        return $this->partitioned;
    }

    /**
     * Creates a cookie copy with a new value.
     */
    public function withValue( ?string $value ): static {
        $cookie = clone $this;
        $cookie->value = $value;

        return $cookie;
    }

    /**
     * Creates a cookie copy with a new domain that the cookie is available to.
     */
    public function withDomain( ?string $domain ): static {
        $cookie = clone $this;
        $cookie->domain = $domain;

        return $cookie;
    }

    /**
     * Creates a cookie copy with a new time the cookie expires.
     */
    public function withExpires( DateTimeInterface|int|string $expire = 0 ): static {
        $cookie = clone $this;
        $cookie->expire = self::expiresTimestamp( $expire );

        return $cookie;
    }

    /**
     * Creates a cookie copy with a new path on the server in which the cookie will be available on.
     */
    public function withPath( string $path ): static {
        $cookie = clone $this;
        $cookie->path = '' === $path ? '/' : $path;

        return $cookie;
    }

    /**
     * Creates a cookie copy that only be transmitted over a secure HTTPS connection from the client.
     */
    public function withSecure( bool $secure = true ): static {
        $cookie = clone $this;
        $cookie->secure = $secure;

        return $cookie;
    }

    /**
     * Creates a cookie copy that be accessible only through the HTTP protocol.
     */
    public function withHttpOnly( bool $httpOnly = true ): static {
        $cookie = clone $this;
        $cookie->httpOnly = $httpOnly;

        return $cookie;
    }

    /**
     * Creates a cookie copy that uses no url encoding.
     */
    public function withRaw( bool $raw = true ): static {
        if ( $raw && false !== strpbrk( $this->name, self::RESERVED_CHARS_LIST ) ) {
            throw new InvalidArgumentException( sprintf( 'The cookie name "%s" contains invalid characters.', $this->name ) );
        }

        $cookie = clone $this;
        $cookie->raw = $raw;

        return $cookie;
    }

    /**
     * Creates a cookie copy that is tied to the top-level site in cross-site context.
     */
    public function withPartitioned( bool $partitioned = true ): static {
        $cookie = clone $this;
        $cookie->partitioned = $partitioned;

        return $cookie;
    }

    /**
     * Whether this cookie is about to be cleared.
     */
    public function isCleared(): bool {
        return 0 !== $this->expire && time() > $this->expire;
    }

    /**
     * @param bool $default The default value of the "secure" flag when it is set to null
     */
    public function setSecureDefault( bool $default ): void {
        $this->secureDefault = $default;
    }

    /**
     * Converts expires formats to a unix timestamp.
     */
    private static function expiresTimestamp( DateTimeInterface|int|string $expire = 0 ): int {
        // convert expiration time to a Unix timestamp
        if ( $expire instanceof DateTimeInterface ) {
            $expire = $expire->format( 'U' );
        } elseif ( ! is_numeric( $expire ) ) {
            $expire = strtotime( $expire );

            if ( false === $expire ) {
                throw new InvalidArgumentException( 'The cookie expiration time is not valid.' );
            }
        }

        return 0 < $expire ? (int) $expire : 0;
    }
}
