<?php

/*
 * Copyright 2011 Piotr Śliwa <peter.pl7@gmail.com>
 *
 * License information is in LICENSE file
 */

namespace PHPPdf\Core;

use PHPPdf\Core\Parser\ColorPaletteParser;
use PHPPdf\Parser\Parser;
use PHPPdf\Core\Parser\StylesheetConstraint;
use PHPPdf\Core\Parser\CachingStylesheetConstraint;
use PHPPdf\Core\Parser\DocumentParser;
use PHPPdf\Core\Configuration\Loader;
use PHPPdf\Core\Node\TextTransformator;
use PHPPdf\Core\Parser\StylesheetParser;
use PHPPdf\Core\Parser\ComplexAttributeFactoryParser;
use PHPPdf\Core\Parser\FontRegistryParser;
use PHPPdf\Cache\Cache;
use PHPPdf\Cache\NullCache;
use PHPPdf\DataSource\DataSource;
use PHPPdf\Core\Parser\NodeFactoryParser;

/**
 * Simple facade whom encapsulate logical complexity of this library
 *
 * @author Piotr Śliwa <peter.pl7@gmail.com>
 */
class Facade
{
    private $documentParser;
    private $stylesheetParser;
    private $document;
    private $cache;
    private $loaded = false;
    private $useCacheForStylesheetConstraint = false;
    private $configurationLoader;
    private $colorPaletteParser;

    public function __construct(Loader $configurationLoader, Document $document, DocumentParser $documentParser, StylesheetParser $stylesheetParser)
    {
        $this->configurationLoader = $configurationLoader;
        $this->configurationLoader->setUnitConverter($document);
        
        $this->setCache(NullCache::getInstance());
        $documentParser->setDocument($document);
        $nodeManager = $documentParser->getNodeManager();
        if($nodeManager)
        {
            $documentParser->addListener($nodeManager);
        }
        $this->setDocumentParser($documentParser);
        $this->setStylesheetParser($stylesheetParser);
        $this->setDocument($document);
    }

    public function setCache(Cache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * @return DocumentParser
     */
    public function getDocumentParser()
    {
        return $this->documentParser;
    }

    public function getStylesheetParser()
    {
        return $this->stylesheetParser;
    }

    public function setDocumentParser(DocumentParser $documentParser)
    {
        $this->documentParser = $documentParser;
    }

    public function setStylesheetParser(StylesheetParser $stylesheetParser)
    {
        $this->stylesheetParser = $stylesheetParser;
    }
    
    public function setColorPaletteParser(Parser $colorPaletteParser)
	{
		$this->colorPaletteParser = $colorPaletteParser;
	}
	
	protected function getColorPaletteParser()
	{
	    if(!$this->colorPaletteParser)
	    {
	        $this->colorPaletteParser = new ColorPaletteParser();
	    }
	    
	    return $this->colorPaletteParser;
	}

	/**
     * Returns pdf document object
     * 
     * @return PHPPdf\Core\Document
     */
    public function getDocument()
    {
        return $this->document;
    }

    private function setDocument(Document $document)
    {
        $this->document = $document;
    }

    private function setFacadeConfiguration(FacadeConfiguration $facadeConfiguration)
    {
        $this->facadeConfiguration = $facadeConfiguration;
    }

    /**
     * @param boolean $useCache Stylsheet constraints should be cached?
     */
    public function setUseCacheForStylesheetConstraint($useCache)
    {
        $this->useCacheForStylesheetConstraint = (bool) $useCache;
    }

    /**
     * Convert text document to pdf document
     * 
     * @return string Content of pdf document
     */
    public function render($documentContent, $stylesheetContent = null, $colorPaletteContent = null)
    {
        $colorPalette = new ColorPalette((array) $this->configurationLoader->createColorPalette());
        
        if($colorPaletteContent)
        {
            $colorPalette->merge($this->parseColorPalette($colorPaletteContent));
        }
        
        $this->document->setColorPalette($colorPalette);
        
        $complexAttributeFactory = $this->configurationLoader->createComplexAttributeFactory();
        
        $this->getDocument()->setComplexAttributeFactory($complexAttributeFactory);
        $fontDefinitions = $this->configurationLoader->createFontRegistry();
        $this->getDocument()->addFontDefinitions($fontDefinitions);
        $this->getDocumentParser()->setComplexAttributeFactory($complexAttributeFactory);
        $this->getDocumentParser()->setNodeFactory($this->configurationLoader->createNodeFactory());

        $stylesheetConstraint = $this->retrieveStylesheetConstraint($stylesheetContent);

        $relativePathToResources = str_replace('\\', '/', realpath(__DIR__.'/../Resources'));
        $documentContent = str_replace('%resources%', $relativePathToResources, $documentContent);

        $pageCollection = $this->getDocumentParser()->parse($documentContent, $stylesheetConstraint);
        $this->updateStylesheetConstraintCacheIfNecessary($stylesheetConstraint);
        unset($stylesheetConstraint);

        return $this->doRender($pageCollection);
    }
    
    private function parseColorPalette($colorPaletteContent)
    {        
        if(!$colorPaletteContent instanceof DataSource)
        {
            $colorPaletteContent = DataSource::fromString($colorPaletteContent);
        }
        
        $id = $colorPaletteContent->getId();
        
        if($this->cache->test($id))
        {
            $colors = (array) $this->cache->load($id);
        }
        else
        {
            $colors = (array) $this->getColorPaletteParser()->parse($colorPaletteContent->read());
            $this->cache->save($colors, $id);
        }

        return $colors;
    }

    private function doRender($pageCollection)
    {
        $this->getDocument()->draw($pageCollection);
        $pageCollection->flush();
        unset($pageCollection);
        
        $content = $this->getDocument()->render();
        $this->getDocument()->initialize();

        return $content;
    }

    public function retrieveStylesheetConstraint($stylesheetXml)
    {
       $stylesheetConstraint = null;

        if($stylesheetXml)
        {
            if(!$stylesheetXml instanceof DataSource)
            {
                $stylesheetXml = DataSource::fromString($stylesheetXml);
            }

            if(!$this->useCacheForStylesheetConstraint)
            {
                $stylesheetConstraint = $this->parseStylesheet($stylesheetXml);
            }
            else
            {
                $stylesheetConstraint = $this->loadStylesheetConstraintFromCache($stylesheetXml);
            }
        }

        return $stylesheetConstraint;
    }

    /**
     * @return StylesheetConstraint
     */
    private function parseStylesheet(DataSource $ds)
    {
        return $this->getStylesheetParser()->parse($ds->read());
    }

    private function loadStylesheetConstraintFromCache(DataSource $ds)
    {
        $id = $ds->getId();
        if($this->cache->test($id))
        {
            $stylesheetConstraint = $this->cache->load($id);
        }
        else
        {
            $csc = new CachingStylesheetConstraint();
            $csc->setCacheId($id);
            $this->getStylesheetParser()->setRoot($csc);
            
            $stylesheetConstraint = $this->parseStylesheet($ds);
            $this->cache->save($stylesheetConstraint, $id);
        }

        return $stylesheetConstraint;
    }

    private function updateStylesheetConstraintCacheIfNecessary(StylesheetConstraint $constraint = null)
    {
        if($constraint && $this->useCacheForStylesheetConstraint && $constraint->isResultMapModified())
        {
            $this->cache->save($constraint, $constraint->getCacheId());
        }
    }
}