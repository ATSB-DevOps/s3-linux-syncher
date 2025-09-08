<?php
namespace S3Sync;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class S3Manager {
    private $db;
    private $logger;
    
    public function __construct() {
        $this->db = (new Database())->getConnection();
        $this->logger = new Logger();
    }
    
    public function getSettings($id = null) {
        if ($id) {
            $stmt = $this->db->prepare("SELECT * FROM s3_settings WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        
        $stmt = $this->db->query("SELECT * FROM s3_settings ORDER BY created_at DESC");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    public function getActiveSettings() {
        $stmt = $this->db->query("SELECT * FROM s3_settings WHERE is_active = 1 LIMIT 1");
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
    
    public function saveSettings($data) {
        try {
            $stmt = $this->db->prepare("INSERT INTO s3_settings (name, endpoint, region, access_key, secret_key, bucket) 
                                        VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['name'],
                $data['endpoint'] ?? null,
                $data['region'],
                $data['access_key'],
                $data['secret_key'],
                $data['bucket']
            ]);
            $id = $this->db->lastInsertId();
            $this->logger->info('S3 settings saved', ['settings_id' => $id, 'name' => $data['name']]);
            return $id;
        } catch (\Exception $e) {
            $this->logger->logException($e, ['operation' => 'save_settings', 'name' => $data['name'] ?? 'unknown']);
            throw $e;
        }
    }
    
    public function updateSettings($id, $data) {
        try {
            $stmt = $this->db->prepare("UPDATE s3_settings 
                                        SET name = ?, endpoint = ?, region = ?, access_key = ?, secret_key = ?, bucket = ?, updated_at = CURRENT_TIMESTAMP 
                                        WHERE id = ?");
            $stmt->execute([
                $data['name'],
                $data['endpoint'] ?? null,
                $data['region'],
                $data['access_key'],
                $data['secret_key'],
                $data['bucket'],
                $id
            ]);
            $this->logger->info('S3 settings updated', ['settings_id' => $id, 'name' => $data['name']]);
        } catch (\Exception $e) {
            $this->logger->logException($e, ['operation' => 'update_settings', 'settings_id' => $id]);
            throw $e;
        }
    }
    
    public function setActive($id) {
        try {
            $this->db->exec("UPDATE s3_settings SET is_active = 0");
            $stmt = $this->db->prepare("UPDATE s3_settings SET is_active = 1 WHERE id = ?");
            $stmt->execute([$id]);
            $this->logger->info('S3 settings activated', ['settings_id' => $id]);
        } catch (\Exception $e) {
            $this->logger->logException($e, ['operation' => 'set_active_settings', 'settings_id' => $id]);
            throw $e;
        }
    }
    
    public function deleteSettings($id) {
        $stmt = $this->db->prepare("DELETE FROM s3_settings WHERE id = ?");
        $stmt->execute([$id]);
    }
    
    public function createS3Client($settings) {
        $config = [
            'version' => 'latest',
            'region' => $settings['region'],
            'credentials' => [
                'key' => $settings['access_key'],
                'secret' => $settings['secret_key']
            ]
        ];
        
        if (!empty($settings['endpoint'])) {
            $config['endpoint'] = $settings['endpoint'];
            $config['use_path_style_endpoint'] = true;
            // For S3-compatible services, disable signature version 4 requirement
            $config['signature_version'] = 'v4';
            // Disable SSL verification for testing (enable in production)
            $config['http'] = [
                'verify' => true
            ];
        }
        
        return new S3Client($config);
    }
    
    public function testConnection($settings) {
        try {
            $client = $this->createS3Client($settings);
            
            // For S3-compatible services, try listing objects instead of headBucket
            // as some services don't support headBucket properly
            if (!empty($settings['bucket'])) {
                try {
                    // First try to list objects (works better with R2 and other S3-compatible services)
                    $result = $client->listObjectsV2([
                        'Bucket' => $settings['bucket'],
                        'MaxKeys' => 1
                    ]);
                    $this->logger->logS3Operation('test_connection', true, [
                        'settings_name' => $settings['name'] ?? 'unknown',
                        'bucket' => $settings['bucket'],
                        'method' => 'listObjectsV2'
                    ]);
                    return ['success' => true, 'message' => 'Connection successful - Bucket accessible'];
                } catch (AwsException $e) {
                    // If list fails, try headBucket as fallback
                    try {
                        $result = $client->headBucket(['Bucket' => $settings['bucket']]);
                        return ['success' => true, 'message' => 'Connection successful - Bucket exists'];
                    } catch (AwsException $e2) {
                        // If both fail, try to create a test object
                        try {
                            $testKey = '.s3sync-test-' . time();
                            $client->putObject([
                                'Bucket' => $settings['bucket'],
                                'Key' => $testKey,
                                'Body' => 'test'
                            ]);
                            // Clean up test object
                            $client->deleteObject([
                                'Bucket' => $settings['bucket'],
                                'Key' => $testKey
                            ]);
                            return ['success' => true, 'message' => 'Connection successful - Write access confirmed'];
                        } catch (AwsException $e3) {
                            throw $e; // Throw original error
                        }
                    }
                }
            } else {
                // No bucket specified, try to list buckets
                $result = $client->listBuckets();
                return ['success' => true, 'message' => 'Connection successful - Can list buckets'];
            }
        } catch (AwsException $e) {
            $errorMessage = $e->getAwsErrorMessage() ?: $e->getMessage();
            $this->logger->logS3Operation('test_connection', false, [
                'settings_name' => $settings['name'] ?? 'unknown',
                'bucket' => $settings['bucket'] ?? 'not_specified',
                'error' => $errorMessage
            ]);
            return ['success' => false, 'message' => 'AWS Error: ' . $errorMessage];
        } catch (\Exception $e) {
            $this->logger->logException($e, ['operation' => 'test_connection', 'settings' => $settings['name'] ?? 'unknown']);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    public function syncFile($client, $localPath, $s3Key, $bucket) {
        try {
            $result = $client->putObject([
                'Bucket' => $bucket,
                'Key' => $s3Key,
                'SourceFile' => $localPath,
                'ACL' => 'private'
            ]);
            return ['success' => true, 'message' => 'File uploaded successfully'];
        } catch (AwsException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}