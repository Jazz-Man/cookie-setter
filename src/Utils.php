<?php

namespace JazzMan\Cookie;

use InvalidArgumentException;

class Utils {

    public static function split( string $header, string $separators ): array {
        if ( '' === $separators ) {
            throw new InvalidArgumentException( 'At least one separator must be specified.' );
        }

        $quotedSeparators = preg_quote( $separators, '/' );

        preg_match_all('
            /
                (?!\s)
                    (?:
                        # quoted-string
                        "(?:[^"\\\\]|\\\\.)*(?:"|\\\\|$)
                    |
                        # token
                        [^"'.$quotedSeparators.']+
                    )+
                (?<!\s)
            |
                # separator
                \s*
                (?<separator>['.$quotedSeparators.'])
                \s*
            /x', trim( $header ), $matches, PREG_SET_ORDER );

        return self::groupParts( $matches, $separators );
    }

    /**
     * Decodes a quoted string.
     *
     * If passed an unquoted string that matches the "token" construct (as
     * defined in the HTTP specification), it is passed through verbatim.
     */
    public static function unquote( string $s ): string {
        return preg_replace( '/\\\\(.)|"/', '$1', $s );
    }

    /**
     * Combines an array of arrays into one associative array.
     *
     * Each of the nested arrays should have one or two elements. The first
     * value will be used as the keys in the associative array, and the second
     * will be used as the values, or true if the nested array only contains one
     * element. Array keys are lowercased.
     *
     * Example:
     *
     *     Utils::combine([['foo', 'abc'], ['bar']])
     *     // => ['foo' => 'abc', 'bar' => true]
     */
    public static function combine( array $parts ): array {
        $assoc = [];

        foreach ( $parts as $part ) {
            $name = strtolower( $part[0] );
            $value = $part[1] ?? true;
            $assoc[$name] = $value;
        }

        return $assoc;
    }

    private static function groupParts( array $matches, string $separators, bool $first = true ): array {
        $separator = $separators[0];
        $separators = substr( $separators, 1 ) ?: '';
        $i = 0;

        if ( '' === $separators && ! $first ) {
            $parts = [''];

            foreach ( $matches as $match ) {
                if ( ! $i && isset( $match['separator'] ) ) {
                    $i = 1;
                    $parts[1] = '';
                } else {
                    $parts[$i] .= self::unquote( $match[0] );
                }
            }

            return $parts;
        }

        $parts = [];
        $partMatches = [];

        foreach ( $matches as $match ) {
            if ( ( $match['separator'] ?? null ) === $separator ) {
                ++$i;
            } else {
                $partMatches[$i][] = $match;
            }
        }

        foreach ( $partMatches as $matches ) {
            $parts[] = '' === $separators ? self::unquote( $matches[0][0] ) : self::groupParts( $matches, $separators, false );
        }

        return $parts;
    }
}
