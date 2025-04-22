<?php
namespace MagoArab\CdnIntegration\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

class Config
{
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;
    
    /**
     * @var Filesystem
     */
    protected $filesystem;
    
    /**
     * @var array
     */
    protected $defaultPaths = [
        '/static/frontend/*/css/',
        '/static/frontend/*/js/',
        '/static/frontend/*/fonts/',
        '/static/frontend/*/images/',
        '/media/catalog/',
        '/media/wysiwyg/',
        '/static/_cache/merged/',
        '/static/_cache/minified/'
    ];
    
    /**
     * Constructor
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param Filesystem $filesystem
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Filesystem $filesystem
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->filesystem = $filesystem;
    }
    
    /**
     * Get optimized default static paths
     *
     * @return array
     */
    public function getDefaultStaticPaths()
    {
        return $this->defaultPaths;
    }
    
    /**
     * Check if URL path is optimal for CDN
     *
     * @param string $urlPath
     * @return bool
     */
    public function isPathOptimalForCdn($urlPath)
    {
        // Always include merged and minified files
        if (strpos($urlPath, '/_cache/merged/') !== false || 
            strpos($urlPath, '/_cache/minified/') !== false) {
            return true;
        }
        
        $staticDir = $this->filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW)->getAbsolutePath();
        $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
        
        // Get file size if available
        $localPath = '';
        if (strpos($urlPath, '/static/') === 0) {
            $path = substr($urlPath, 8); // Remove '/static/'
            $localPath = $staticDir . $path;
        } elseif (strpos($urlPath, '/media/') === 0) {
            $path = substr($urlPath, 7); // Remove '/media/'
            $localPath = $mediaDir . $path;
        }
        
        if (!empty($localPath) && file_exists($localPath)) {
            $fileSize = filesize($localPath);
            
            // Small files (under 50KB) that aren't images might be better served locally
            $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
            if ($fileSize < 51200 && !in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) {
                return false;
            }
            
            // Files over 2MB might be better cached by browser than fetched from CDN repeatedly
            if ($fileSize > 2097152) {
                return true;
            }
        }
        
        // Check for optimal CDN path patterns
        foreach ($this->defaultPaths as $pattern) {
            if ($this->pathMatchesPattern($urlPath, $pattern)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if path matches a pattern with wildcards
     *
     * @param string $path
     * @param string $pattern
     * @return bool
     */
    private function pathMatchesPattern($path, $pattern)
    {
        $pattern = preg_quote($pattern, '/');
        $pattern = str_replace('\*', '.*', $pattern);
        return (bool) preg_match('/^' . $pattern . '/', $path);
    }
}