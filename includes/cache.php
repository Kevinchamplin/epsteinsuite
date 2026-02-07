<?php
/**
 * Simple File-Based Cache System
 * Stores cached data in /cache directory with TTL support
 */

class Cache {
    private static $cacheDir = null;
    private static $defaultTtl = 3600; // 1 hour default
    
    private static function getCacheDir() {
        if (self::$cacheDir === null) {
            self::$cacheDir = __DIR__ . '/../cache';
            if (!is_dir(self::$cacheDir)) {
                mkdir(self::$cacheDir, 0755, true);
            }
        }
        return self::$cacheDir;
    }
    
    private static function getFilePath($key) {
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        return self::getCacheDir() . '/' . $safeKey . '.cache';
    }
    
    /**
     * Get cached value
     * @param string $key Cache key
     * @return mixed|null Cached value or null if not found/expired
     */
    public static function get($key) {
        $file = self::getFilePath($key);
        
        if (!file_exists($file)) {
            return null;
        }
        
        $content = file_get_contents($file);
        $data = @unserialize($content);
        
        if ($data === false || !isset($data['expires']) || !isset($data['value'])) {
            return null;
        }
        
        // Check if expired
        if ($data['expires'] > 0 && $data['expires'] < time()) {
            @unlink($file);
            return null;
        }
        
        return $data['value'];
    }
    
    /**
     * Set cached value
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds (0 = never expires)
     * @return bool Success
     */
    public static function set($key, $value, $ttl = null) {
        if ($ttl === null) {
            $ttl = self::$defaultTtl;
        }
        
        $file = self::getFilePath($key);
        $data = [
            'expires' => $ttl > 0 ? time() + $ttl : 0,
            'value' => $value,
            'created' => time(),
        ];
        
        return file_put_contents($file, serialize($data), LOCK_EX) !== false;
    }
    
    /**
     * Delete cached value
     * @param string $key Cache key
     * @return bool Success
     */
    public static function delete($key) {
        $file = self::getFilePath($key);
        if (file_exists($file)) {
            return @unlink($file);
        }
        return true;
    }
    
    /**
     * Clear all cache
     * @return int Number of files deleted
     */
    public static function clear() {
        $dir = self::getCacheDir();
        $count = 0;
        
        if (is_dir($dir)) {
            $files = glob($dir . '/*.cache');
            foreach ($files as $file) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }
        
        return $count;
    }
    
    /**
     * Get cache stats
     * @return array Stats about cache
     */
    public static function stats() {
        $dir = self::getCacheDir();
        $files = glob($dir . '/*.cache');
        $totalSize = 0;
        $expired = 0;
        $valid = 0;
        
        foreach ($files as $file) {
            $totalSize += filesize($file);
            $content = file_get_contents($file);
            $data = @unserialize($content);
            
            if ($data && isset($data['expires'])) {
                if ($data['expires'] > 0 && $data['expires'] < time()) {
                    $expired++;
                } else {
                    $valid++;
                }
            }
        }
        
        return [
            'total_files' => count($files),
            'valid' => $valid,
            'expired' => $expired,
            'total_size' => $totalSize,
            'total_size_human' => self::formatBytes($totalSize),
            'cache_dir' => $dir,
        ];
    }
    
    private static function formatBytes($bytes) {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }
    
    /**
     * Get or set cached value with callback
     * @param string $key Cache key
     * @param callable $callback Function to generate value if not cached
     * @param int $ttl Time to live
     * @return mixed Cached or generated value
     */
    public static function remember($key, $callback, $ttl = null) {
        $value = self::get($key);
        
        if ($value !== null) {
            return $value;
        }
        
        $value = $callback();
        self::set($key, $value, $ttl);
        
        return $value;
    }
}
