<?php

/*
 * to.uri.st HTTP client
 *
 * The to.uri.st database has an Atom based HTTP interface. This client gives
 * you interactive commandline access to the to.uri.st database via this Atom
 * interface.
 *
 * This script requires SimpleXML.
 */

if (!extension_loaded('simplexml')) die('SimpleXML extension not found.');

define('VERBOSE', FALSE);

define('NS_ATOM', 'http://www.w3.org/2005/Atom');
define('NS_ATOM_REVISION', 'http://purl.org/atompub/revision/1.0');
define('NS_GEORSS', 'http://www.georss.org/georss');
define('NS_OPENSEARCH', 'http://a9.com/-/spec/opensearch/1.1/');
define('NS_OPENSEARCH_GEO', 'http://a9.com/-/opensearch/extensions/geo/1.0/');

array_shift($argv);
if (count($argv) == 0) {
    $url = new URL('http://to.uri.st/index.atom');
} else {
    $url = new URL($argv[0]);
}

list($code, $document, $headers) = $url->exec();

$mimetype = explode(';', $headers['Content-Type']);
processDocument($url, $mimetype[0], $document);

function processDocument($url, $mimetype, $document) {
    switch($mimetype) {
    case 'application/atomserv+xml':
        $actions = parseIndexDocument($document, $url);
        break;
    case 'application/atom+xml':
        if (strpos($document, '<feed') !== FALSE) { // feed
            $actions = parseAtomFeed($document, $url);
        } elseif (strpos($document, '<entry') !== FALSE) { // entry
            $actions = parseAtomEntry($document, $url);
        }
        break;
    default:
        echo "Unknown format: ".mimetype."\n";
        exit;
    }
    if (!$actions) {
        echo "Nothing found to do\n";
        exit;
    }
    echo "Available actions:\n";
    $items = array_keys($actions);
    $choice = doMenu($items);
    $chosenUrl = $actions[$items[$choice - 1]];
    list($code, $document, $headers) = $chosenUrl->exec();
    if (substr($code, 0, 1) == '2') {
        list($mimetype) = explode(';', $headers['Content-Type']);
        echo "Success: ".$code." (".$mimetype.")\n";
        if ($document) {
            processDocument($chosenUrl, $mimetype, $document);
        }
    } else {
        echo "Error: ".$code."\n";
    }
}

function doMenu($items) {
    $foo = 0;
    foreach ($items as $name) {
        $foo++;
        echo " ".$foo.". ".$name."\n";
    }
    $action = (int)trim(fgets(STDIN));
    if ($action > 0 && $action <= $foo) {
        return $action;
    }
    echo "Bad choice, please choose an option:\n";
    return doMenu($items);
}

function parseIndexDocument($document, $url) {
    
    $actions = array();
    
    $xml = new SimpleXMLElement($document);
    
    foreach ($xml->workspace->collection as $collection) {
        $attrs = $collection->attributes();
        $atomChildren = $collection->children(NS_ATOM);
        $actions[(string)$atomChildren->title] = new URL((string)$attrs['href'], $url, 'get');
    }
    
    return $actions;
}

function parseAtomFeed($document, $url) {
    
    echo "Found an Atom feed\n";
    
    $xml = new SimpleXMLElement($document);
    $feedUrl = new URL($xml->link['href'], $url);
    
    echo "Feed ID: ".$xml->id."\n";
    echo "Feed URL: ".$feedUrl->url."\n";
    
    $actions = array();
    
    foreach ($xml->entry as $entry) {
        $revision = $entry->children(NS_ATOM_REVISION);
        if ($revision) {
            $number = $revision->revision->attributes();
            $version = ' version '.(string)$number['number'];
        } else {
            $version = '';
        }
        foreach ($entry->link as $link) {
            if ($link['rel'] == 'self') {
                $actions['GET entry'.$version.' "'.(string)$entry->title.'"'] = new URL((string)$link['href'], $url, 'get');
            }
        }
    }
    $actions['POST a new entry'] = new URL($url->url, $url, 'post');
    
    return $actions;
    
}

function parseAtomEntry($document, $url) {
    
    echo "Found an Atom entry\n";
    
    $xml = new SimpleXMLElement($document);
    foreach ($xml->link as $link) {
        switch ($link['rel']) {
        case 'self':
            $entryUrl = new URL($link['href'], $url);
            break;
        case 'via':
            $linkUrl = new URL($link['href'], $url);
            break;
        case 'history':
            $historyUrl = new URL($link['href'], $url);
            break;
        }
    }
    $georssChildren = $xml->children(NS_GEORSS);
    
    echo "Entry ID: ".$xml->id."\n";
    echo "Entry URL: ".$entryUrl->url."\n";
    echo "Title: ".$xml->title."\n";
    echo "Description: ".$xml->content."\n";
    echo "Point: ".$georssChildren->point."\n";
    echo "Published: ".$xml->published."\n";
    echo "Updated: ".$xml->updated."\n";
    echo "Author: ".$xml->author->name."\n";
    echo "Link: ".$linkUrl->url."\n";
    
    $actions = array(
        'PUT' => new URL($entryUrl->url, $url, 'put'),
        'DELETE' => new URL($entryUrl->url, $url, 'delete')
    );
    if ($historyUrl) $actions['History'] = new URL($historyUrl->url, $url, 'get');
    
    return $actions;
}

class URL {
    var $url, $method;
    
    function url($url, $parentUrl = NULL, $method = 'get') {
        $this->url = $this->makeAbsoluteUrl($url, $parentUrl);
        $this->method = $method;
    }
    
    function exec() {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($curl, CURLOPT_MAXREDIRS, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, 160);
        curl_setopt($curl, CURLOPT_USERAGENT, 'to.uri.st API client');
        curl_setopt($curl, CURLOPT_VERBOSE, VERBOSE);
        curl_setopt($curl, CURLOPT_HEADER, TRUE);
        $headers = array(
            'Accept-Language: en-gb,en;q=0.5',
            'Accept: application/atom+xml'
        );
        switch (strtolower($this->method)) {
        case 'post':
            echo "POSTing to ".$this->url."\n";
            curl_setopt($curl, CURLOPT_POST, TRUE);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $this->readData());
            $headers[] = 'Content-Type: application/atom+xml';
            break;
        case 'put':
            echo "PUTting to ".$this->url."\n";
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($curl, CURLOPT_POSTFIELDS, $this->readData());
            $headers[] = 'Content-type: application/atom+xml';
            break;
        case 'delete':
            echo "DELETEing ".$this->url."\n";
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
            break;
        case 'head':
            echo "HEADing to ".$this->url."\n";
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'HEAD');
            break;
        case 'options':
            echo "OPTIONSing to ".$this->url."\n";
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'OPTIONS');
            break;
        default:
            echo "GETting ".$this->url."\n";
        }
        curl_setopt($curl, CURLOPT_URL, $this->url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($curl);
        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (substr($responseCode, 0, 1) == '2') {
            $parts = explode("\r\n\r\n", $response);
            $headers = array();
            foreach ($parts as $part) {
                if (substr($part, 0, 4) == 'HTTP') {
                    $headerStrings = explode("\r\n", array_shift($parts));
                    foreach ($headerStrings as $headerString) {
                        $headerParts = explode(":", $headerString);
                        $headers[trim($headerParts[0])] = trim($headerParts[1]);
                    }
                }
            }
            $content = join("\r\n\r\n", $parts);
            if (isset($headers['Content-Encoding']) && $headers['Content-Encoding'] == 'gzip') {
                $content = gzinflate(substr($content, 10));
            }
        } else {
            $content = '';
            $headers = array();
        }
        return array($responseCode, $content, $headers);
    }
    
    function makeAbsoluteUrl($url, $parentUrl = NULL) {
        $url = html_entity_decode($url, ENT_NOQUOTES);
        
        if ($parentUrl) {
            $parentUrlParts = parse_url($parentUrl->url);
        } else {
            $parentUrlParts = array();
        }
        
        $urlParts = parse_url($url);
        if (isset($urlParts['host']) && !isset($urlParts['path']) && !preg_match('/^[a-z]+:\/\//', $url)) { // not fully qualified
            // parse_url might have decided that the path is the host, so fix that
            $urlParts['path'] = $urlParts['host'];
            unset($urlParts['host']);
        }
        if (isset($urlParts['scheme']) && $urlParts['scheme']) {
            return str_replace(' ', '%20', $url);
        } else {
            $url = $parentUrlParts['scheme'];
        }
        $url .= '://';
        if (isset($urlParts['host']) && $urlParts['host']) {
            $url .= $urlParts['host'];
        } else {
            $url .= $parentUrlParts['host'];
        }
        if (isset($urlParts['path']) && $urlParts['path']) {
            if (substr($urlParts['path'], 0, 1) != '/') {
                $path = dirname($parentUrlParts['path']);
                if ($path != '.' && $path != '/' && $path != '\\') {
                    while (substr($urlParts['path'], 0, 3) == '../') { // sort out ..'s in path
                        $urlParts['path'] = substr($urlParts['path'], 3);
                        $path = dirname($path);
                    }
                    if ($path == '/') {
                        $path = '';	
                    }
                    $url .= $path.'/';
                } else {
                    $url .= '/';
                }
            }
            $url .= $urlParts['path'];
        } else {
            $url .= $parentUrlParts['path'];
        }
        if (isset($urlParts['query']) && $urlParts['query']) {
            $url .= '?'.$urlParts['query'];
        }
        return str_replace(' ', '%20', $url);
    }
    
    function readData() {
        echo "Enter entry data to send:\n";
        $atom = "<?xml version=\"1.0\" encoding=\"utf-8\"?>
<entry xmlns=\"http://www.w3.org/2005/Atom\" xmlns:georss=\"http://www.georss.org/georss\">";
        echo "Title: ";
        $title = trim(fgets(STDIN));
        if ($title) $atom .= "<title>".$title."</title>";
        echo "Description: ";
        $description = trim(fgets(STDIN));
        if ($description) $atom .= "<content type=\"html\">".$description."</content>";
        echo "Latitude: ";
        $lat = trim(fgets(STDIN));
        echo "Longitude: ";
        $lng = trim(fgets(STDIN));
        if (is_numeric($lat) && is_numeric($lng)) $atom .= "<georss:point>".$lat." ".$lng."</georss:point>";
        return $atom."</entry>";
    }
}

?>
