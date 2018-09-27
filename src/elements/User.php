<?php
namespace verbb\feedme\elements;

use verbb\feedme\base\Element;
use verbb\feedme\base\ElementInterface;
use verbb\feedme\helpers\AssetHelper;

use Craft;
use craft\db\Query;
use craft\elements\Asset as AssetElement;
use craft\elements\User as UserElement;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
use craft\records\User as UserRecord;

use Cake\Utility\Hash;

class User extends Element implements ElementInterface
{
    // Properties
    // =========================================================================

    public static $name = 'User';
    public static $class = 'craft\elements\User';

    public $element;
    public $status;


    // Templates
    // =========================================================================

    public function getGroupsTemplate()
    {
        return 'feed-me/_includes/elements/user/groups';
    }

    public function getColumnTemplate()
    {
        return 'feed-me/_includes/elements/user/column';
    }

    public function getMappingTemplate()
    {
        return 'feed-me/_includes/elements/user/map';
    }


    // Public Methods
    // =========================================================================

    public function getGroups()
    {
        $result = false;

        // User are only allowed for Craft Pro
        if (Craft::$app->getEdition() == Craft::Pro) {
            $groups = Craft::$app->userGroups->getAllGroups();

            $result = count($groups) ? $groups : true;
        }

        return $result;
    }

    public function getQuery($settings, $params = [])
    {
        $query = UserElement::find();

        $criteria = array_merge([
            'status' => null,
        ], $params);

        $siteId = Hash::get($settings, 'siteId');

        if ($siteId) {
            $criteria['siteId'] = $siteId;
        }

        Craft::configure($query, $criteria);

        return $query;
    }

    public function setModel($settings)
    {
        $this->element = new UserElement();

        $this->status = null;

        $siteId = Hash::get($settings, 'siteId');

        if ($siteId) {
            $this->element->siteId = $siteId;
        }

        return $this->element;
    }

    public function afterSave($data, $settings)
    {
        $groupsIds = Hash::get($data, 'groups');
        $profilePhoto = Hash::get($data, 'photo');

        // User status can't be set on the element anymore, only directly on the record.
        if ($this->status) {
            $record = UserRecord::findOne($this->element->id);

            switch ($this->status) {
                case 'locked';
                    $record->locked = true;
                    break;
                case 'suspended';
                    $record->locked = false;
                    $record->suspended = true;
                    break;
                case 'archived':
                    $record->locked = false;
                    $record->suspended = false;
                    $record->archived = true;
                    break;
                case 'pending':
                    $record->locked = false;
                    $record->suspended = false;
                    $record->archived = false;
                    $record->pending = true;
                    break;
                case 'active':
                    $record->suspended = false;
                    $record->locked = false;
                    $record->setActive();
                    break;
            }

            $record->save(false);
        }

        if ($groupsIds) {
            Craft::$app->users->assignUserToGroups($this->element->id, $groupsIds);
        }

        if ($profilePhoto) {
            $filename = basename($profilePhoto);

            Craft::$app->users->saveUserPhoto($profilePhoto, $this->element, $filename);
        } 
    }


    // Protected Methods
    // =========================================================================

    protected function parseGroups($feedData, $fieldInfo)
    {
        $value = $this->fetchArrayValue($feedData, $fieldInfo);

        $newGroupsIds = [];
        $groupIds = [];

        foreach ($value as $key => $dataValue) {
            if (is_numeric($dataValue)) {
                $newGroupsIds[] = $dataValue;

                continue;
            }

            // Try to get via name
            $result = (new Query())
                ->select(['id', 'name', 'handle'])
                ->from(['{{%usergroups}}'])
                ->where(['name' => $dataValue])
                ->orWhere(['handle' => $dataValue])
                ->one();

            if (!$result) {
                continue;
            }

            $newGroupsIds[] = $result['id'];
        }

        $removeFromExisting = Hash::get($fieldInfo, 'options.removeFromExisting');
        $existingGroupsIds = Hash::extract($this->element->groups, '{n}.id');

        if ($removeFromExisting) {
            $groupIds = $newGroupsIds;
        } else {
            $groupIds = array_unique(array_merge($newGroupsIds, $existingGroupsIds));
        }

        // Dealt with in `afterSave` as we need to combine permissions
        return $groupIds;
    }

    protected function parsePhoto($feedData, $fieldInfo)
    {
        $value = $this->fetchSimpleValue($feedData, $fieldInfo);

        $upload = Hash::get($fieldInfo, 'options.upload');
        $conflict = Hash::get($fieldInfo, 'options.conflict');

        // Try to find an existing element
        $urlToUpload = null;

        // If we're uploading files, this will need to be an absolute URL. If it is, save until later.
        // We also don't check for existing assets here, so break out instantly.
        if ($upload && UrlHelper::isAbsoluteUrl($value)) {
            $urlToUpload = $value;

            // If we're opting to use the already uploaded asset, we can check here
            if ($conflict === AssetElement::SCENARIO_INDEX) {
                $value = AssetHelper::getRemoteUrlFilename($value);
            }
        }

        // Search anywhere in Craft
        $foundElement = AssetElement::findOne(['filename' => $value]);

        // Do we want to match existing elements, and was one found?
        if ($foundElement && $conflict === AssetElement::SCENARIO_INDEX) {
            // If so, we still need to make a copy temporarily, as the Users service needs to add it in properly
            return $foundElement->getCopyOfFile();
        }

        // We can't find an existing asset, we need to download it, or plain ignore it
        if ($urlToUpload) {
            $folderId = $this->_prepareUserPhotosFolder($this->element);

            $uploadedElementIds = AssetHelper::fetchRemoteImage([$urlToUpload], $fieldInfo, null, $this->element, $folderId);

            if ($uploadedElementIds) {
                $uploadedAsset = AssetElement::findOne(['id' => $uploadedElementIds[0]]);

                // We still need to make a copy temporarily, as the Users service needs to add it in properly
                return $uploadedAsset->getCopyOfFile();
            }
        }
    }

    protected function parseStatus($feedData, $fieldInfo)
    {
        $value = $this->fetchSimpleValue($feedData, $fieldInfo);

        $this->status = $value;

        return null;
    }



    // Private Methods
    // =========================================================================

    private function _prepareUserPhotosFolder($user)
    {
        $assetsService = Craft::$app->getAssets();
        $volumes = Craft::$app->getVolumes();

        $volumeId = Craft::$app->getSystemSettings()->getSetting('users', 'photoVolumeId');
        $volume = $volumes->getVolumeById($volumeId);

        $subpath = (string)Craft::$app->getSystemSettings()->getSetting('users', 'photoSubpath');

        if ($subpath) {
            $subpath = Craft::$app->getView()->renderObjectTemplate($subpath, $user);
        }

        return $assetsService->ensureFolderByFullPathAndVolume($subpath, $volume);
    }

}