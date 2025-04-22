<?php
namespace MagoArab\CdnIntegration\Controller\Adminhtml\Cdn;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MagoArab\CdnIntegration\Helper\Data as Helper;
use MagoArab\CdnIntegration\Model\Github\Api as GithubApi;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

class AnalyzeUrls extends Action
{
    /**
     * Authorization level
     */
    const ADMIN_RESOURCE = 'MagoArab_CdnIntegration::config';

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var Helper
     */
    protected $helper;
    
    /**
     * @var GithubApi
     */
    protected $githubApi;
    
    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Helper $helper
     * @param GithubApi $githubApi
     * @param Filesystem $filesystem
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Helper $helper,
        GithubApi $githubApi = null,
        Filesystem $filesystem = null
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->helper = $helper;
        $this->githubApi = $githubApi;
        $this->filesystem = $filesystem;
    }

    /**
     * Analyze URLs from storefront page or upload to GitHub based on parameter
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();
        
        try {
            if (!$this->helper->isEnabled()) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('CDN Integration is disabled.')
                ]);
            }
            
            // Check if this is an upload request
            $isUpload = $this->getRequest()->getParam('upload');
            
            if ($isUpload) {
                try {
                    return $this->processUpload();
                } catch (\Exception $e) {
                    $this->helper->log('Error in uploadToGithub: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), 'error');
                    return $resultJson->setData([
                        'success' => false,
                        'message' => 'Error in upload process: ' . $e->getMessage()
                    ]);
                }
            }
            
            // Normal analyze flow
            $storeUrl = $this->getRequest()->getParam('store_url');
            $specificUrl = $this->getRequest()->getParam('specific_url');
            $scanLinkedPages = (bool)$this->getRequest()->getParam('scan_linked_pages', false);
            $scanDepth = (int)$this->getRequest()->getParam('scan_depth', 1);
            
            // Get existing URLs if provided
            $existingUrlsJson = $this->getRequest()->getParam('existing_urls');
            $existingUrls = [];

            if (!empty($existingUrlsJson)) {
                try {
                    $existingUrls = json_decode($existingUrlsJson, true);
                    if (!is_array($existingUrls)) {
                        $existingUrls = [];
                    }
                } catch (\Exception $e) {
                    $this->helper->log('Error decoding existing URLs: ' . $e->getMessage(), 'error');
                }
            }

            if (empty($storeUrl)) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('Store URL is required.')
                ]);
            }

            // If a specific URL is provided, validate it's from the same domain
            $urlToAnalyze = $storeUrl;
            if (!empty($specificUrl)) {
                // Extract domain from store URL
                $storeDomain = parse_url($storeUrl, PHP_URL_HOST);
                
                // Check if specific URL is from the same domain
                $specificDomain = parse_url($specificUrl, PHP_URL_HOST);
                
                if ($specificDomain === $storeDomain) {
                    $urlToAnalyze = $specificUrl;
                    $this->helper->log("Using specific URL for analysis: {$specificUrl}", 'info');
                } else {
                    $this->helper->log("Specific URL domain doesn't match store domain. Using store URL instead.", 'warning');
                }
            }

            $urls = [];
            
            // If scan linked pages is enabled, perform a more thorough analysis
            if ($scanLinkedPages) {
                $urls = $this->analyzeSiteWithDepth($urlToAnalyze, $scanDepth);
            } else {
                // Fetch homepage content to analyze
                $content = $this->fetchUrl($urlToAnalyze);
                if (empty($content)) {
                    return $resultJson->setData([
                        'success' => false,
                        'message' => __('Failed to fetch page content. Please check the URL.')
                    ]);
                }
                
                // Extract URLs
                $urls = $this->extractUrls($content);
            }
            
            if (empty($urls)) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('No suitable URLs found to analyze.')
                ]);
            }
            
            // Filter out core files that shouldn't be served via CDN
            $coreFilesToExclude = [
                'requirejs-config.js',
                'require.js',
                'mixins.js',
                'jquery.js',
                'jquery.min.js',
                'mage/requirejs/mixins.js',
                'mage/polyfill.js',
                'mage/translate.js',
                'mage/common.js',
                'mage/mage.js',
                'mage/bootstrap.js'
            ];
            
            $filteredUrls = [];
            foreach ($urls as $url) {
                $excludeUrl = false;
                foreach ($coreFilesToExclude as $coreFile) {
                    if (strpos($url, $coreFile) !== false) {
                        $excludeUrl = true;
                        break;
                    }
                }
                
                // Also check if URL is already in existing URLs
                if (!$excludeUrl && !empty($existingUrls)) {
                    if (in_array($url, $existingUrls)) {
                        $excludeUrl = true;
                    }
                }
                
                if (!$excludeUrl) {
                    $filteredUrls[] = $url;
                }
            }
            
            $urls = $filteredUrls;
            
            // If no URLs are left after filtering
            if (empty($urls)) {
                return $resultJson->setData([
                    'success' => true,
                    'urls' => [],
                    'message' => __('No new URLs found. The files might already be in your custom URL list or they are core files that should not be served via CDN.')
                ]);
            }
            
            $this->messageManager->addSuccessMessage(
                __('Found %1 URLs to analyze.', count($urls))
            );
            
            return $resultJson->setData([
                'success' => true,
                'urls' => $urls,
                'message' => __('URL analysis completed.')
            ]);
        } catch (\Exception $e) {
            $this->helper->log('Error in AnalyzeUrls::execute: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), 'error');
            $this->messageManager->addExceptionMessage($e, __('An error occurred while analyzing URLs.'));
            
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Analyze site with depth crawling
     *
     * @param string $startUrl
     * @param int $maxDepth
     * @return array
     */
    protected function analyzeSiteWithDepth($startUrl, $maxDepth = 2)
    {
        $allUrls = [];
        $visitedPages = [];
        $pagesToVisit = [$startUrl];
        $currentDepth = 0;
        
        while ($currentDepth < $maxDepth && !empty($pagesToVisit)) {
            $currentDepth++;
            $this->helper->log("Processing pages at depth {$currentDepth}", 'info');
            
            $nextLevelPages = [];
            
            foreach ($pagesToVisit as $pageUrl) {
                if (in_array($pageUrl, $visitedPages)) {
                    continue;
                }
                
                $visitedPages[] = $pageUrl;
                $this->helper->log("Analyzing page: {$pageUrl}", 'info');
                
                // Analyze current page
                $content = $this->fetchUrl($pageUrl);
                if (empty($content)) {
                    continue;
                }
                
                // Extract asset URLs from the page
                $pageAssets = $this->extractUrls($content);
                $allUrls = array_merge($allUrls, $pageAssets);
                
                // If we haven't reached max depth, extract page links to visit in the next level
                if ($currentDepth < $maxDepth) {
                    $pageLinks = $this->extractPageLinks($content, $pageUrl);
                    $nextLevelPages = array_merge($nextLevelPages, $pageLinks);
                }
            }
            
            // Set pages to visit in the next level
            $pagesToVisit = array_diff($nextLevelPages, $visitedPages);
            
            // Limit the number of pages to visit to avoid excessive crawling
            if (count($pagesToVisit) > 10) {
                $pagesToVisit = array_slice($pagesToVisit, 0, 10);
                $this->helper->log("Limited pages to visit to 10 for performance reasons", 'info');
            }
        }
        
        // Clean up and remove duplicates
        return array_unique($allUrls);
    }
    
    /**
     * Process the upload request
     * 
     * @return \Magento\Framework\Controller\Result\Json
     */
    private function processUpload()
    {
        $resultJson = $this->resultJsonFactory->create();
        
        // Check for required dependencies
        if ($this->githubApi === null) {
            $this->helper->log('GithubApi dependency is missing', 'error');
            return $resultJson->setData([
                'success' => false,
                'message' => __('GitHub API service is not available. Please check your module configuration.')
            ]);
        }
        
        if ($this->filesystem === null) {
            $this->helper->log('Filesystem dependency is missing', 'error');
            return $resultJson->setData([
                'success' => false,
                'message' => __('Filesystem service is not available. Please check your module configuration.')
            ]);
        }
        
        $urls = $this->getRequest()->getParam('urls');
        $this->helper->log('Received URLs for upload: ' . $urls, 'info');
        
        if (empty($urls)) {
            return $resultJson->setData([
                'success' => false,
                'message' => __('No URLs provided for upload.')
            ]);
        }
        
        // Decode URLs
        $urls = json_decode($urls, true);
        if (!is_array($urls)) {
            return $resultJson->setData([
                'success' => false,
                'message' => __('Invalid URL format.')
            ]);
        }
        
        // Get file system directories
        try {
            $staticDir = $this->filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW)->getAbsolutePath();
            $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
            
            $this->helper->log("Static directory: {$staticDir}", 'debug');
            $this->helper->log("Media directory: {$mediaDir}", 'debug');
        } catch (\Exception $e) {
            $this->helper->log('Error getting directories: ' . $e->getMessage(), 'error');
            return $resultJson->setData([
                'success' => false,
                'message' => __('Error accessing file system: %1', $e->getMessage())
            ]);
        }
        
        // Initialize results
        $results = [
            'total' => count($urls),
            'success' => 0,
            'failed' => 0,
            'details' => []
        ];
        
        // Process each URL
        foreach ($urls as $url) {
            $this->helper->log("Processing URL: {$url}", 'debug');
            
            try {
                // Determine local file path
                $localPath = '';
                $remotePath = '';
                
                if (strpos($url, '/static/') === 0) {
                    $path = substr($url, 8); // Remove '/static/'
                    $localPath = $staticDir . $path;
                    $remotePath = $path;
                } elseif (strpos($url, '/media/') === 0) {
                    $path = substr($url, 7); // Remove '/media/'
                    $localPath = $mediaDir . $path;
                    $remotePath = $path;
                } else {
                    // Skip unsupported URLs
                    $results['failed']++;
                    $results['details'][] = [
                        'url' => $url,
                        'success' => false,
                        'message' => __('Unsupported URL format.')
                    ];
                    continue;
                }
                
                $this->helper->log("Local path: {$localPath}", 'debug');
                $this->helper->log("Remote path: {$remotePath}", 'debug');
                
                // Check if file exists
                if (!file_exists($localPath)) {
                    $this->helper->log("File not found: {$localPath}", 'error');
                    $results['failed']++;
                    $results['details'][] = [
                        'url' => $url,
                        'success' => false,
                        'message' => __('File not found: %1', $localPath)
                    ];
                    continue;
                }
                
                // Upload file to GitHub
                $success = $this->githubApi->uploadFile($localPath, $remotePath);
                
                if ($success) {
                    $this->helper->log("Successfully uploaded {$url} to GitHub", 'info');
                    $results['success']++;
                    $results['details'][] = [
                        'url' => $url,
                        'success' => true,
                        'message' => __('Successfully uploaded to GitHub')
                    ];
                } else {
                    $this->helper->log("Failed to upload {$url} to GitHub", 'error');
                    $results['failed']++;
                    $results['details'][] = [
                        'url' => $url,
                        'success' => false,
                        'message' => __('Failed to upload to GitHub')
                    ];
                }
            } catch (\Exception $e) {
                $this->helper->log('Exception processing URL ' . $url . ': ' . $e->getMessage(), 'error');
                $results['failed']++;
                $results['details'][] = [
                    'url' => $url,
                    'success' => false,
                    'message' => $e->getMessage()
                ];
            }
        }
        
        // Create success or failure message
        if ($results['failed'] > 0) {
            $message = __('Upload completed with issues: %1 successful, %2 failed, %3 total.', 
                $results['success'], 
                $results['failed'], 
                $results['total']
            );
        } else {
            $message = __('All %1 files were successfully uploaded to GitHub.', $results['success']);
        }
        
        $this->messageManager->addSuccessMessage($message);
        
        return $resultJson->setData([
            'success' => true,
            'results' => $results,
            'message' => $message
        ]);
    }
    
    /**
     * Fetch URL content using cURL
     *
     * @param string $url
     * @return string
     */
    protected function fetchUrl($url)
    {
        try {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($curl, CURLOPT_MAXREDIRS, 5);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_setopt($curl, CURLOPT_ENCODING, ''); // Accept all encodings
            
            $response = curl_exec($curl);
            $error = curl_error($curl);
            curl_close($curl);
            
            if ($error) {
                $this->helper->log("cURL Error: {$error}", 'error');
                return '';
            }
            
            return $response;
        } catch (\Exception $e) {
            $this->helper->log("Exception in fetchUrl: " . $e->getMessage(), 'error');
            return '';
        }
    }
    
    /**
     * Extract URLs from HTML content
     *
     * @param string $content
     * @return array
     */
    protected function extractUrls($content)
    {
        $urls = [];
        
        // Main patterns for asset extraction
        $patterns = [
            // CSS links
            '/<link[^>]*href=[\'"]((?:[^\'"]+\.css)(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Script sources
            '/<script[^>]*src=[\'"]((?:[^\'"]+\.js)(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Images
            '/<img[^>]*src=[\'"]((?:[^\'"]+\.(png|jpg|jpeg|gif|svg|webp))(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Product images specifically 
            '/<img[^>]*class=[\'"]*(?:product-image|catalog-image)[^\'"]* src=[\'"]((?:[^\'"]+\.(png|jpg|jpeg|gif|svg|webp))(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Font files in CSS url()
            '/url\([\'"]?((?:[^\'"]+\.(woff|woff2|ttf|eot|otf))(?:\?[^\'"]*)?)[\'"]?\)/i',
            
            // SVGs
            '/<[^>]*(?:href|src)=[\'"]((?:[^\'"]+\.svg)(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Background images in inline styles
            '/style=[\'"][^"\']*background(?:-image)?:\s*url\([\'"]?([^\'")+\s]+)[\'"]?\)[^"\']*[\'"]/'
        ];
        
        // Font pattern enhancements
        $fontPatterns = [
            // @font-face URLs
            '/@font-face\s*{[^}]*src:\s*url\([\'"]?((?:[^\'"]+\.(woff2?|ttf|eot|otf))(?:\?[^\'"]*)?)[\'"]?\)/im',
            
            // More complex font patterns
            '/src:\s*url\([\'"]?((?:[^\'"]+\.(woff2?|ttf|eot|otf))(?:\?[^\'"]*)?)[\'"]?\)/i'
        ];
        
        // Combine all patterns
        $allPatterns = array_merge($patterns, $fontPatterns);
        
        foreach ($allPatterns as $pattern) {
            // Debug logging
            $this->helper->log("Processing pattern: {$pattern}", 'debug');
            
            // Safe regex execution with error checking
            $matches = [];
            $result = @preg_match_all($pattern, $content, $matches);
            
            if ($result === false) {
                // Log compilation error
                $error = error_get_last();
                $this->helper->log("Regex compilation error: " . print_r($error, true), 'error');
                continue;
            }
            
            if (!empty($matches[1])) {
                foreach ($matches[1] as $url) {
                    // Normalize URL
                    $url = $this->normalizeUrl($url);
                    
                    // Check and add
                    if ($this->isValidUrl($url)) {
                        $urls[] = $url;
                    }
                }
            }
        }
        
        // Enhanced patterns for merged files and RequireJS resources
        $mergedPatterns = [
            '/\/static\/_cache\/merged\/[^"\'\s\)><]+/i',
            '/\/static\/_cache\/minified\/[^"\'\s\)><]+/i',
            '/text!(\/static\/[^!]+)/i',
            '/"(\/static\/[^"]+)"/i',
            // Additional pattern for RequireJS loaded resources
            '/["\']((?:\/static|\/media)\/[^"\']+\.(?:js|css|svg|png|jpg|jpeg|gif|woff|woff2|ttf|eot)(?:\?[^\'"]*)?)["\']/'
        ];
        
        foreach ($mergedPatterns as $pattern) {
            $matches = [];
            $result = @preg_match_all($pattern, $content, $matches);
            
            if ($result !== false) {
                $matchGroup = !empty($matches[1]) ? $matches[1] : $matches[0];
                foreach ($matchGroup as $url) {
                    if ($this->isValidUrl($url)) {
                        $urls[] = $url;
                    }
                }
            }
        }
        
        // Look for JSON data that might contain URLs
        if (preg_match_all('/\{[^}]+\}/m', $content, $jsonMatches)) {
            foreach ($jsonMatches[0] as $jsonString) {
                if (preg_match_all('/"(\/(?:static|media)\/[^"]+)"/i', $jsonString, $jsonPaths)) {
                    foreach ($jsonPaths[1] as $asset) {
                        if ($this->isValidUrl($asset)) {
                            $urls[] = $asset;
                        }
                    }
                }
            }
        }
        
        // Remove duplicates and sort
        $urls = array_unique($urls);
        sort($urls);
        
        return $urls;
    }

    /**
     * Extract page links from HTML content
     *
     * @param string $content
     * @param string $baseUrl
     * @return array
     */
    protected function extractPageLinks($content, $baseUrl)
    {
        $links = [];
        $baseUrlInfo = parse_url($baseUrl);
        $baseDomain = isset($baseUrlInfo['host']) ? $baseUrlInfo['host'] : '';
        
        // Extract links from <a> tags
        preg_match_all('/<a[^>]+href=["\'](.*?)["\']/i', $content, $matches);
        
        if (isset($matches[1]) && !empty($matches[1])) {
            foreach ($matches[1] as $href) {
                // Skip empty, anchor, javascript, mailto links
                if (empty($href) || strpos($href, '#') === 0 || strpos($href, 'javascript:') === 0 || 
                    strpos($href, 'mailto:') === 0 || strpos($href, 'tel:') === 0) {
                    continue;
                }
                
                // Handle absolute and relative paths
                $absoluteUrl = $this->makeAbsoluteUrl($href, $baseUrl);
                
                // Check if URL is from the same domain
                $linkInfo = parse_url($absoluteUrl);
                $linkDomain = isset($linkInfo['host']) ? $linkInfo['host'] : '';
                
                if ($linkDomain === $baseDomain) {
                    // Filter out static files - we only want page links
                    $extension = pathinfo(parse_url($absoluteUrl, PHP_URL_PATH), PATHINFO_EXTENSION);
                    $extensions = ['css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'woff', 'woff2', 'ttf', 'eot'];
                    
                    if (empty($extension) || !in_array(strtolower($extension), $extensions)) {
                        $links[] = $absoluteUrl;
                    }
                }
            }
        }
        
        return array_unique($links);
    }

    /**
     * Convert a relative URL to an absolute URL
     *
     * @param string $url
     * @param string $baseUrl
     * @return string
     */
    protected function makeAbsoluteUrl($url, $baseUrl)
    {
        // If URL is already absolute
        if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
            return $url;
        }
        
        $baseUrlInfo = parse_url($baseUrl);
        $basePath = isset($baseUrlInfo['path']) ? $baseUrlInfo['path'] : '/';
        
        // If path is absolute (starts with /)
        if (strpos($url, '/') === 0) {
            $scheme = isset($baseUrlInfo['scheme']) ? $baseUrlInfo['scheme'] : 'http';
            $host = isset($baseUrlInfo['host']) ? $baseUrlInfo['host'] : '';
            
            return $scheme . '://' . $host . $url;
        }
        
        // Relative path
        $path = dirname($basePath);
        if ($path === '/' || $path === '\\') {
            $path = '';
        }
        
        return rtrim($baseUrlInfo['scheme'] . '://' . $baseUrlInfo['host'] . $path, '/') . '/' . $url;
    }

    // Normalize URL method
    private function normalizeUrl($url)
    {
        // Remove domain
        if (strpos($url, 'http') === 0) {
            $parsedUrl = parse_url($url);
            $url = $parsedUrl['path'] ?? $url;
        }
        
        // Remove parameters
        $url = strtok($url, '?');
        
        return $url;
    }

    // URL validation method
    private function isValidUrl($url)
    {
        return !empty($url) && 
               (strpos($url, '/static/') === 0 || strpos($url, '/media/') === 0);
    }
}