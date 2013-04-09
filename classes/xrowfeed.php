<?php

class xrowFeed
{

    function __construct( $params )
    {
        $this->type = $params['type'];
        $this->search = $params['search'];
        $this->ini = eZINI::instance( "xrowfeed.ini" );
        self::generateRSSFeed();
    }

    function generateRSSFeed()
    {
        try
        {
            self::fetchFeed();
        }
        catch ( Exception $e )
        {
            echo 'Error occured: ', $e->getMessage(), "\n";
            eZExecution::cleanExit();
        }
        
        $tpl = eZTemplate::factory();
        $this->feed = new ezcFeed();
        $this->feed->generator = eZSys::serverURL();
        $link = '/xrowfeed/rss/' . $this->type . '/' . $this->search;
        $this->feed->id = self::transformURI( null, true, 'full' );
        $this->feed->title = 'Feed Channel - ' . eZSys::serverURL();
        $item->published = date( DATE_RFC822 );
        $this->feed->link = eZSys::serverURL();
        $this->feed->description = 'Feed Channel';
        $this->feed->language = eZLocale::currentLocaleCode();
        
        foreach ( $this->nodes as $node )
        {
            $dm = $node->dataMap();
            
            $defaultAttr = $this->ini->variable( "RSSAttributes", "AttributeIdentifier" );
            $altAttr = $this->ini->variable( "RSSAttributes", "AlternativeIdentifier" );
            
            $item = $this->feed->add( 'item' );
            $item->title = $node->getName();
            $item->published = date( DATE_RFC822, $node->object()->attribute('published') );
            $item->link = self::transformURI( $node->attribute( 'url_alias' ), true, 'full' );
            $item->id = self::transformURI( $node->attribute( 'url_alias' ), true, 'full' );
            
            if ( ! $dm[$defaultAttr[$node->classIdentifier()]] instanceof ezContentObjectAttribute )
            {
                eZDebug::writeError( "An error occured using Defaultattribute: {$dm[$defaultAttr[$node->classIdentifier()]]}", __METHOD__ );
                continue;
            }
            
            if ( $dm[$defaultAttr[$node->classIdentifier()]]->attribute( 'has_content' ) )
            {
                $this->cache['cache']['text'] = $defaultAttr[$node->classIdentifier()];
            }
            else
            {
                if ( ! empty( $altAttr[$node->classIdentifier()] ) )
                {
                    $this->cache['cache']['text'] = $altAttr[$node->classIdentifier()];
                }
            }
            $textAttribute = $dm[$this->cache['cache']['text']];
            
            if ( ! $textAttribute instanceof ezContentObjectAttribute )
            {
                eZDebug::writeError( "An error occured using Textattribute: {$this->cache['cache']['text']}", __METHOD__ );
                continue;
            }
            
            if ( $textAttribute->attribute( 'data_type_string' ) == eZXMLTextType::DATA_TYPE_STRING )
            {
                $outputHandler = new xrowRSSOutputHandler( $textAttribute->attribute( 'data_text' ), false );
                $item->description = $outputHandler->outputText();
                $item->description->text = str_replace( '&', '&amp;', $item->description->text );
            }
            else
            {
                $item->description = htmlspecialchars( $textAttribute->attribute( 'content' ) );
            }
        }
        return $this->feed;
    }

    function fetchFeed()
    {
        $classArray = $this->ini->variable( "Classes", "ClassIdentifier" );
        $limit = $this->ini->variable( "RSSSettings", "NumberOfObjectsList" );
        
        switch ( $this->type )
        {
            case 'keyword':
                $sortBy = $this->ini->variable( "RSSSettings", "SortBy" );
                if ( ! empty( $sortBy ) )
                {
                    $sortBy = explode( "|", $sortBy[$this->type] );
                    $sortBy = array( 
                        $sortBy[0] , 
                        $sortBy[1] 
                    );
                }
                else
                {
                    $sortBy = array();
                }
                $classIDArray = array();
                foreach ( $classArray as $class )
                {
                    $id = eZContentClass::fetchByIdentifier( $class, false );
                    $classIDArray[] = $id['id'];
                }
                $result = eZContentFunctionCollection::fetchKeyword( urldecode( $this->search ), $classIDArray, $offset, $limit[$this->type], $owner, $sortBy, $parentNodeID, true, $strictMatching );
                
                foreach ( $result['result'] as $i => $item )
                {
                    $this->nodes[] = $item['link_object'];
                }
                break;
            case 'node':
                if ( is_numeric( $this->search ) )
                {
                    $params = array();
                    $params['Limit'] = $limit[$this->type];
                    $params['ClassFilterType'] = 'include';
                    $params['ClassFilterArray'] = $classArray;
                    
                    if ( ( is_array( $treeNode = eZContentObjectTreeNode::subTreeByNodeID( $params, $this->search ) ) ) && ! empty( $treeNode ) )
                    {
                        $this->nodes = $treeNode;
                    }
                    else
                    {
                        if ( $treeNode instanceof eZContentObjectTreeNode )
                        {
                            $this->nodes = array( 
                                $treeNode 
                            );
                        }
                    }
                }
                else
                {
                    throw new Exception( 'No numeric value in ' . __METHOD__ );
                }
                break;
            default:
                throw new Exception( "{$this->type} as Feedtype is not supported in " . __METHOD__ );
                eZExecution::cleanExit();
        }
        
        return null;
    }

    function transformURI( $href, $ignoreIndexDir = false, $serverURL = null )
    {
        if ( $serverURL === null )
        {
            $serverURL = eZURI::getTransformURIMode();
        }
        if ( preg_match( "#^[a-zA-Z0-9]+:#", $href ) || substr( $href, 0, 2 ) == '//' )
            return false;
        
        if ( strlen( $href ) == 0 )
            $href = '/';
        else 
            if ( $href[0] == '#' )
            {
                $href = htmlspecialchars( $href );
                return true;
            }
            else 
                if ( $href[0] != '/' )
                {
                    $href = '/' . $href;
                }
        
        $sys = eZSys::instance();
        $dir = ! $ignoreIndexDir ? $sys->indexDir() : $sys->wwwDir();
        $serverURL = $serverURL === 'full' ? $sys->serverURL() : '';
        $href = $serverURL . $dir . $href;
        if ( ! $ignoreIndexDir )
        {
            $href = preg_replace( "#^(//)#", "/", $href );
            $href = preg_replace( "#(^.*)(/+)$#", "\$1", $href );
        }
        $href = str_replace( '&amp;amp;', '&amp;', htmlspecialchars( $href ) );
        
        if ( $href == "" )
            $href = "/";
        
        return $href;
    }
    
    public $type;
    public $search;
    public $ini;
    public $nodes = array();
    public $feed;
    public $cache;
}
?>