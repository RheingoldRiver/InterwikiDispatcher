<?php
namespace MediaWiki\Extension\InterwikiDispatcher;

use Config;
use Title;

class InterwikiDispatcherHooks implements \MediaWiki\Hook\GetLocalURLHook {
    private array $rules;

    public function __construct( Config $config ) {
        $this->rules = $config->get( 'IWDPrefixes' );
    }

    /**
     * Generates external links for titles in configured interwikis.
     *
     * @param Title $title Title object of page
     * @param string &$url String value as output (out parameter, can modify)
     * @param string $query Query options as string passed to Title::getLocalURL()
     * @return bool|void True or no return value to continue or false to abort
     */
    public function onGetLocalURL( $title, &$url, $query ) {
        foreach ( $this->rules as $key => $rule ) {
            if ( $this->getLocalURLSingle( $rule, $title, $url, $query ) === false ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Attempts to match a title against an external wikifarm interwiki rule, and generates a URL when successful.
     *
     * @param array $rule Farm interwiki settings.
     * @param Title $title Title object of page
     * @param string &$url String value as output (out parameter)
     * @param string $query Query options as string passed
     * @return bool True when $url not modified, false otherwise
     */
    private function getLocalURLSingle( $rule, $title, &$url, $query ) {
        if ( $title->getInterwiki() !== $rule['interwiki'] ) {
            return true;
        }
        if ( ( $rule['baseTransOnly'] ?? false ) === true && preg_match( "/(?:^|&)action=(?:render|raw)(?:&|$)/Si", $query ) ) {
            return true;
        }
        $dbkey = $title->getDBKey();
        $subprefix = $rule['subprefix'] ?? '';
        if ( $subprefix !== '' ) $subprefix .= '_*:_*';
        $m = [];
        if ( $dbkey !== '' && preg_match( "/^$subprefix(?:([a-z-]{2,12})\.)?([a-z\d-]{1,50})(?:_*:_*(.*))?$/Si", $dbkey, $m ) ) {
            if ( !isset( $m[3] ) ) {
                $m[3] = '';
            }
            [ , $language, $wiki, $article ] = $m;
            $wiki = strtolower( $wiki );
            $language = strtolower( $language );
            $wikiExistsCallback = $rule['wikiExistsCallback'] ?? [ $this, 'doesWikiExist' ];
            if ( $wikiExistsCallback( $rule, $wiki, $language ) !== true ) {
                return true;
            }
            if ( $language === '' ) {
                # $articlePath = 'https://$2.wiki.gg/wiki/$1'
                $articlePath = $rule['url'];
            }
            else {
                # $articlePath = 'https://$2.wiki.gg/$3/wiki/$1'
                $articlePath = $rule['urlInt'] ?? null;
                if ( $articlePath === null ) {
                    return true;
                }
                $articlePath = str_replace( '$3', $language, $articlePath );
            }
            $articlePath = str_replace( '$2', $wiki, $articlePath );
            $namespace = $title->getNsText();
            if ( $namespace != '' ) {
                # Can this actually happen? Interwikis shouldn't be parsed.
                # Yes! It can in interwiki transclusion. But... it probably shouldn't.
                $namespace .= ':';
            }
            $article = $namespace . ( $article ?? '' );
            $url = str_replace( '$1', wfUrlencode( $article ), $articlePath );
            $url = wfAppendQuery( $url, $query );
            return false;
        }
        return true;
    }

    /**
     * @param array $rule
     * @param string &$wiki
     * @param string &$language
     * @return bool True to continue or false to abort
     */
    private function doesWikiExist( $rule, &$wiki, &$language ) {
        global $wgLocalDatabases;
        if ( $language === '' ) {
            $dbname = $rule['dbname'] ?? null;
        }
        else {
            $dbname = $rule['dbnameInt'] ?? null;
        }
        if ( $dbname !== null ) {
            $dbname = str_replace( '$2', $wiki, $dbname );
            $dbname = str_replace( '$3', $language, $dbname );
            return in_array( $dbname, $wgLocalDatabases );
        }
        return true;
    }
}
