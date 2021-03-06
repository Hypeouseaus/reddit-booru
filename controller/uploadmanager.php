<?php

namespace Controller {

    use Api;
    use Lib;
    use stdClass;

    class UploadManager implements Page {

        // I'm trying to comprehend why PostData::reverseImageSearch
        // multiplies count by two... Until then, this number just _looks_ small...
        const REPOST_CHECK_LIMIT = 2;

        public static function render() {

            $imageUrl = Lib\Url::Get('imageUrl', null);
            $action = Lib\Url::Get('action', null);
            $retVal = null;

            switch ($action) {
                case 'upload':
                    if ($imageUrl) {
                        $retVal = self::_uploadFromUrl($imageUrl);
                    } else if (count($_POST) && count($_FILES)) {
                        $retVal = self::uploadFromFile();
                    }
                    break;
                case 'status':
                    $retVal = self::_checkUploadStatus();
                    break;
                default:
                    return Redditbooru::render();
                    break;
            }

            header('Content-Type: text/javascript; charset=utf-8');
            echo json_encode($retVal);
            exit;

        }

        private static function _uploadFromUrl($imageUrl) {
            return self::_handleImageUpload($imageUrl);
        }

        public static function uploadFromFile($moveOnly = false) {
            $uploadId = $_POST['uploadId'];

            $data = new stdClass;
            $data->error = true;
            if (is_numeric($uploadId)) {
                $data->uploadId = $uploadId;
                $file = $_FILES['upload'];
                $fileName = self::getUploadedFilePath($uploadId);

                if (is_uploaded_file($file['tmp_name']) && move_uploaded_file($file['tmp_name'], $fileName)) {
                    if ($moveOnly) {
                        $data->fileName = $fileName;
                        $data->error = false;
                    } else {
                        $data = self::_handleImageUpload($fileName, $uploadId);
                    }
                } else {
                    $data->message = 'Unable to upload file';
                }
            }

            return $data;
        }

        /**
         * Does a repost check on the image and/or creates a database entry and saves it
         */
        private static function _handleImageUpload($imageUrl, $uploadId = null) {
            $retVal = new stdClass;

            $force = Lib\Url::GetBool('force');
            $retVal->uploadId = $uploadId ?: $imageUrl;
            $image = Api\Image::createFromUrl($imageUrl);
            if (null !== $image) {

                // Do a quick repost check
                if (!$force) {
                    $reposts = Images::getByImage([ 'image' => $image, 'imageUri' => $imageUrl, 'count' => self::REPOST_CHECK_LIMIT ]);
                    $retVal->similar = $reposts;
                    if ($reposts && $reposts->identical) {
                        $retVal->identical = $reposts->identical;
                    }
                }

                if (!isset($retVal->identical) || $force) {
                    // Sync to the database and save
                    if ($image->sync()) {
                        $retVal->imageId = $image->id;
                        $retVal->imageUrl = $image->getFilename(true);
                        $retVal->thumb = Thumb::createThumbFilename($retVal->imageUrl);
                    } else {
                        $retVal->error = true;
                        $retVal->message = 'Error syncing image to database';
                    }
                } else {
                    $retVal->thumb = Thumb::createThumbFilename($imageUrl);
                }

            } else {
                $retVal->error = true;
                $retVal->message = 'Error reading image';
            }

            return $retVal;
        }

        private static function _checkUploadStatus() {
            $retVal = Lib\Http::getActiveDownloads();
            return count($retVal) > 0 ? $retVal : null;
        }

        public static function getUploadedFilePath($uploadId) {
            return sys_get_temp_dir() . '/image_' . $_SERVER['REMOTE_ADDR'] . '_' . $uploadId;
        }

    }

}