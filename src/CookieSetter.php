<?php

namespace JazzMan\CookieSetter;

/**
 * Class CookieSetter.
 */
class CookieSetter
{

    /**
     * @var bool[]
     */
    private static array $_is_browser_compatible = [];

    /*
     * sets cookie
     * setcookie ( string $name [, string $value = "" [, array $options = [] ]] ) : bool
     * setcookie signature which comes with php 7.3.0
     * supported $option keys: expires, path, domain, secure, httponly and samesite
     * possible $option[samesite] values: None, Lax or Strict
     */

    public static function setcookie(string $name, ?string $value = null, array $options = []): bool
    {
        $same_site = $options['samesite'] ?? '';
        $is_secure = isset($options['secure']) ? (bool)$options['secure'] : is_ssl();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $path = $options['path'] ?? COOKIEPATH;
        $domain = $options['domain'] ?? COOKIE_DOMAIN;

        if (null === $value) {
            $expires = time() - 3600;
        } else {
            $expires = $options['expires'] ?? 0;
        }

        if (1 === version_compare('7.3.0', PHP_VERSION)) {
            unset($options['samesite'], $options['secure']);

            $is_httponly = isset($options['httponly']) && $options['httponly'];

            $result = setcookie($name, $value, $expires, $path, $domain);

            if (self::isBrowserSameSiteCompatible($user_agent)) {
                $new_headers = [];
                $headers_list = array_reverse(headers_list());
                $is_modified = false;

                foreach ($headers_list as $_header) {
                    if (!$is_modified && str_starts_with($_header, 'Set-Cookie: ' . $name)) {
                        $additional_labels = [];

                        $is_secure = ('None' === $same_site ? true : $is_secure);

                        $new_label = '; HttpOnly';

                        if ($is_httponly && !str_contains($_header, $new_label)) {
                            $additional_labels[] = $new_label;
                        }

                        $new_label = '; Secure';

                        if ($is_secure && !str_contains($_header, $new_label)) {
                            $additional_labels[] = $new_label;
                        }

                        $new_label = '; SameSite=' . $same_site;

                        if (!str_contains($_header, $new_label)) {
                            $additional_labels[] = $new_label;
                        }

                        $_header .= implode('', $additional_labels);
                        $is_modified = true;
                    }
                    $new_headers[] = $_header;
                }

                header_remove();
                $new_headers = array_reverse($new_headers);

                foreach ($new_headers as $_header) {
                    header($_header, false);
                }
            }
        } else {
            if (false === self::isBrowserSameSiteCompatible($user_agent)) {
                $same_site = '';
            }

            $is_secure = ('None' === $same_site ? true : $is_secure);

            $options['samesite'] = $same_site;
            $options['secure'] = $is_secure;
            $options['expires'] = $expires;
            $options['path'] = $path;
            $options['domain'] = $domain;

            $result = setcookie($name, $value, $options);
        }

        return $result;
    }

    public static function isBrowserSameSiteCompatible(string $user_agent): bool
    {
        $user_agent_key = md5($user_agent);
        $self_check = self::_getIsBrowserCompatible($user_agent_key);

        if (null !== $self_check) {
            return $self_check;
        }

        // check Chrome
        if (true === preg_match('#(CriOS|Chrome)/([0-9]*)#', $user_agent, $matches)) {
            $version = $matches[2];

            if (67 > $version) {
                self::_setIsBrowserCompatible($user_agent_key, false);

                return false;
            }
        }

        // check iOS
        if (true === preg_match('#iP.+; CPU .*OS (\d+)_\d#', $user_agent, $matches)) {
            $version = $matches[1];

            if (13 > $version) {
                self::_setIsBrowserCompatible($user_agent_key, false);

                return false;
            }
        }

        // check MacOS 10.14
        if (true === preg_match('#Macintosh;.*Mac OS X (\d+)_(\d+)_.*AppleWebKit#', $user_agent, $matches)) {
            $version_major = $matches[1];
            $version_minor = $matches[2];

            if (10 === $version_major && 14 === $version_minor) {
                // check Safari
                if (true === preg_match('#Version/.* Safari/#', $user_agent)) {
                    self::_setIsBrowserCompatible($user_agent_key, false);

                    return false;
                }

                // check Embedded Browser
                if (true === preg_match('#AppleWebKit/[.\d]+ \(KHTML, like Gecko\)#', $user_agent)) {
                    self::_setIsBrowserCompatible($user_agent_key, false);

                    return false;
                }
            }
        }

        // check UC Browser
        if (true === preg_match('#UCBrowser/(\d+)\.(\d+)\.(\d+)#', $user_agent, $matches)) {
            $version_major = $matches[1];
            $version_minor = $matches[2];
            $version_build = $matches[3];

            if (12 === $version_major && 13 === $version_minor && 2 === $version_build) {
                self::_setIsBrowserCompatible($user_agent_key, false);

                return false;
            }
        }

        self::_setIsBrowserCompatible($user_agent_key, true);

        return true;
    }

    private static function _setIsBrowserCompatible(string $user_agent_key, bool $value): void
    {
        self::$_is_browser_compatible[$user_agent_key] = $value;
    }

    private static function _getIsBrowserCompatible(string $user_agent_key): ?bool
    {
        if (isset(self::$_is_browser_compatible[$user_agent_key])) {
            return self::$_is_browser_compatible[$user_agent_key];
        }

        return null;
    }
}
