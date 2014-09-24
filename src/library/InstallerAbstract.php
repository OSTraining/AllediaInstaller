<?php
/**
 * @package   AllediaInstaller
 * @contact   www.ostraining.com, support@ostraining.com
 * @copyright 2013-2014 Open Source Training, LLC. All rights reserved
 * @license   http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 */

defined('_JEXEC') or die();

abstract class AllediaInstallerAbstract
{
    /**
     * @var array Obsolete folders/files to be deleted - use admin/site/media for location
     */
    protected $obsoleteItems = array();

    /**
     * @var JInstaller
     */
    protected $installer = null;

    /**
     * @var SimpleXMLElement
     */
    protected $manifest = null;

    /**
     * @var string
     */
    protected $mediaFolder = null;

    /**
     * @var array
     */
    protected $messages = array();

    /**
     * @var string
     */
    protected $type;

    /**
     * @var string
     */
    protected $group;

    /**
     * @param JInstallerAdapterComponent $parent
     *
     * @return void
     */
    public function initprops($parent)
    {
        $this->installer = $parent->get('parent');
        $this->manifest  = $this->installer->getManifest();
        $this->messages  = array();

        if ($media = $this->manifest->media) {
            $path              = JPATH_SITE . '/' . $media['folder'] . '/' . $media['destination'];
            $this->mediaFolder = $path;
        }

        $attributes  = (array) $this->manifest->attributes();
        $attributes  = $attributes['@attributes'];
        $this->type  = $attributes['type'];
        $this->group = $attributes['group'];

        // Load the installer default language
        $language = JFactory::getLanguage();
        $language->load('lib_allediainstaller.sys', ALLEDIA_INSTALLER_PATH);
    }

    /**
     * @param JInstallerAdapterComponent $parent
     *
     * @return bool
     */
    public function install($parent)
    {
        return true;
    }

    /**
     * @param JInstallerAdapterComponent $parent
     *
     * @return bool
     */
    public function discover_install($parent)
    {
        return $this->install($parent);
    }

    /**
     * @param JInstallerAdapterComponent $parent
     *
     * @return void
     */
    public function uninstall($parent)
    {
        $this->initprops($parent);
        $this->uninstallRelated();
        $this->showMessages();
    }

    /**
     * @param JInstallerAdapterComponent $parent
     *
     * @return bool
     */
    public function update($parent)
    {
        return true;
    }

    /**
     * @param string                     $type
     * @param JInstallerAdapterComponent $parent
     *
     * @return bool
     */
    public function preFlight($type, $parent)
    {
        $this->initprops($parent);

        if ($type == 'update') {
            $this->clearUpdateServers();
        }

        return true;
    }

    /**
     * @param string                     $type
     * @param JInstallerAdapterComponent $parent
     *
     * @return void
     */
    public function postFlight($type, $parent)
    {
        $this->installRelated();
        $this->clearObsolete();

        $this->showMessages();

        // @TODO: check this
        // // Show additional installation messages
        // $file = strpos($type, 'install') === false ? $type : 'install';
        // $path = JPATH_ADMINISTRATOR . '/components/com_simplerenew/views/welcome/tmpl/' . $file . '.php';
        // if (file_exists($path)) {
        //     JFactory::getLanguage()->load('com_simplerenew', JPATH_ADMINISTRATOR . '/components/com_simplerenew');
        //     require_once $path;
        // }
    }

    /**
     * Install related extensions
     *
     * @return void
     */
    protected function installRelated()
    {
        if ($this->manifest->relatedExtensions) {
            $installer      = new JInstaller();
            $source         = $this->installer->getPath('source');
            $extensionsPath = $source . '/extensions';

            foreach ($this->manifest->relatedExtensions->extension as $extension) {
                $path = $extensionsPath . '/' . (string)$extension;

                $attributes = (array) $extension->attributes();
                if (!empty($attributes)) {
                    $attributes = $attributes['@attributes'];
                }

                if (is_dir($path)) {
                    $type    = $attributes['type'];
                    $element = $attributes['element'];

                    $group = '';
                    if (isset($attributes['group'])) {
                        $group  = $attributes['group'];
                    }

                    $current = $this->findExtension($type, $element, $group);
                    $isNew   = empty($current);

                    $typeName = ucfirst(trim(($group ? : '') . ' ' . $type));

                    // Check if we have a higher version installed
                    if (!$isNew) {
                        $currentManifestPath = $this->getManifestPath($type, $element, $group);
                        $currentManifest = $this->getInfoFromManifest($currentManifestPath);

                        $tmpInstaller = new JInstaller();
                        $tmpInstaller->setPath('source', $path);
                        $newManifest = $tmpInstaller->getManifest();
                        unset($tmpInstaller);

                        // Avoid to update for an outdated version
                        $currentVersion = $currentManifest->get('version');
                        $newVersion     = (string)$newManifest->version;
                        if (version_compare($currentVersion, $newVersion, '>')) {
                            $this->setMessage(
                                JText::sprintf(
                                    'LIB_ALLEDIAINSTALLER_RELATED_ALREADY_UPDATED',
                                    strtolower($typeName),
                                    $element,
                                    $newVersion,
                                    $currentVersion
                                ),
                                'warning'
                            );

                            // Skip the install for this extension
                            continue;
                        }
                    }

                    $text = 'LIB_ALLEDIAINSTALLER_RELATED_' . ($isNew ? 'INSTALL' : 'UPDATE');
                    if ($installer->install($path)) {
                        $this->setMessage(JText::sprintf($text, $typeName, $element));
                        if ($isNew) {
                            $current = $this->findExtension($type, $element, $group);

                            if (isset($attributes['publish']) && (bool) $attributes['publish']) {
                                $current->publish();
                            }

                            if ($type === 'plugin') {
                                if (isset($attributes['ordering'])) {
                                    $this->setPluginOrder($current, (int) $attributes['ordering']);
                                }
                            }
                        }
                    } else {
                        $this->setMessage(JText::sprintf($text . '_FAIL', $typeName, $element), 'error');
                    }
                }
            }
        }
    }

    /**
     * Uninstall the related extensions that are useless without the component
     */
    protected function uninstallRelated()
    {
        if ($this->manifest->relatedExtensions) {
            $installer      = new JInstaller();
            $source         = $this->installer->getPath('source');

            foreach ($this->manifest->relatedExtensions->extension as $extension) {
                $attributes = (array) $extension->attributes();
                if (!empty($attributes)) {
                    $attributes = $attributes['@attributes'];
                }

                if (isset($attributes['uninstall']) && (bool) $attributes['uninstall']) {
                    $type    = $attributes['type'];
                    $element = $attributes['element'];

                    $group = '';
                    if (isset($attributes['group'])) {
                        $group  = $attributes['group'];
                    }

                    if ($current = $this->findExtension($type, $element, $group)) {
                        $msg     = 'LIB_ALLEDIAINSTALLER_RELATED_UNINSTALL';
                        $msgtype = 'message';
                        if (!$installer->uninstall($current->type, $current->extension_id)) {
                            $msg .= '_FAIL';
                            $msgtype = 'error';
                        }
                        $this->setMessage(JText::sprintf($msg, ucfirst($type), $element), $msgtype);
                    }
                }
            }
        }
    }

    /**
     * @param string $type
     * @param string $element
     * @param string $group
     *
     * @return JTable
     */
    protected function findExtension($type, $element, $group = null)
    {
        $row = JTable::getInstance('extension');

        $terms = array(
            'type'    => $type,
            'element' => ($type == 'module' ? 'mod_' : '') . $element
        );
        if ($type == 'plugin') {
            $terms['folder'] = $group;
        }

        $eid = $row->find($terms);
        if ($eid) {
            $row->load($eid);
            return $row;
        }
        return null;
    }

    /**
     * Set requested ordering for selected plugin extension
     * Accepted ordering arguments:
     * (n<=1 | first) First within folder
     * (* | last) Last within folder
     * (before:element) Before the named plugin
     * (after:element) After the named plugin
     *
     * @param JTable $extension
     * @param string $order
     *
     * @return void
     */
    protected function setPluginOrder(JTable $extension, $order)
    {
        if ($extension->type == 'plugin' && !empty($order)) {
            $db    = JFactory::getDbo();
            $query = $db->getQuery(true);

            $query->select('extension_id, element');
            $query->from('#__extensions');
            $query->where(
                array(
                    $db->qn('folder') . ' = ' . $db->q($extension->folder),
                    $db->qn('type') . ' = ' . $db->q($extension->type)
                )
            );
            $query->order($db->qn('ordering'));

            $plugins = $db->setQuery($query)->loadObjectList('element');

            // Set the order only if plugin already successfully installed
            if (array_key_exists($extension->element, $plugins)) {
                $target = array(
                    $extension->element => $plugins[$extension->element]
                );
                $others = array_diff_key($plugins, $target);

                if ((is_numeric($order) && $order <= 1) || $order == 'first') {
                    // First in order
                    $neworder = array_merge($target, $others);
                } elseif (($order == '*') || ($order == 'last')) {
                    // Last in order
                    $neworder = array_merge($others, $target);
                } elseif (preg_match('/^(before|after):(\S+)$/', $order, $match)) {
                    // place before or after named plugin
                    $place    = $match[1];
                    $element  = $match[2];
                    $neworder = array();
                    $previous = '';

                    foreach ($others as $plugin) {
                        if ((($place == 'before') && ($plugin->element == $element)) || (($place == 'after') && ($previous == $element))) {
                            $neworder = array_merge($neworder, $target);
                        }
                        $neworder[$plugin->element] = $plugin;
                        $previous                   = $plugin->element;
                    }
                    if (count($neworder) < count($plugins)) {
                        // Make it last if the requested plugin isn't installed
                        $neworder = array_merge($neworder, $target);
                    }
                } else {
                    $neworder = array();
                }

                if (count($neworder) == count($plugins)) {
                    // Only reorder if have a validated new order
                    JModelLegacy::addIncludePath(
                        JPATH_ADMINISTRATOR . '/components/com_plugins/models',
                        'PluginsModels'
                    );
                    $model = JModelLegacy::getInstance('Plugin', 'PluginsModel');

                    $ids = array();
                    foreach ($neworder as $plugin) {
                        $ids[] = $plugin->extension_id;
                    }
                    $order = range(1, count($ids));
                    $model->saveorder($ids, $order);
                }
            }
        }
    }

    /**
     * Display messages from array
     *
     * @return void
     */
    protected function showMessages()
    {
        $app = JFactory::getApplication();
        foreach ($this->messages as $msg) {
            $app->enqueueMessage($msg[0], $msg[1]);
        }
    }

    /**
     * Add a message to the message list
     *
     * @param string $msg
     * @param string $type
     *
     * @return void
     */
    protected function setMessage($msg, $type = 'message')
    {
        $this->messages[] = array($msg, $type);
    }

    /**
     * Delete obsolete files and folders
     */
    protected function clearObsolete()
    {
        if ($this->obsoleteItems) {
            $admin = $this->installer->getPath('extension_administrator');
            $site  = $this->installer->getPath('extension_site');

            $search  = array('#^/admin#', '#^/site#');
            $replace = array($admin, $site);
            if ($this->mediaFolder) {
                $search[]  = '#^/media#';
                $replace[] = $this->mediaFolder;
            }

            foreach ($this->obsoleteItems as $item) {
                $path = preg_replace($search, $replace, $item);
                if (is_file($path)) {
                    $success = JFile::delete($path);
                } elseif (is_dir($path)) {
                    $success = JFolder::delete($path);
                } else {
                    $success = null;
                }
                if ($success !== null) {
                    $this->setMessage('Delete ' . $path . ($success ? ' [OK]' : ' [FAILED]'));
                }
            }
        }
    }

    /**
     * Use this in preflight to clear out obsolete update servers when the url has changed.
     */
    protected function clearUpdateServers()
    {
        $attributes = (array)$this->manifest->attributes();
        $attributes = $attributes['@attributes'];

        $extension = $this->findExtension($attributes['type'], (string)$this->manifest->element, $attributes['group']);

        $db = JFactory::getDbo();
        $db->setQuery('SELECT `update_site_id`
                       FROM `#__update_sites_extensions`
                       WHERE extension_id = ' . (int)$extension->extension_id
        );

        if ($list = $db->loadColumn()) {
            $db->setQuery('DELETE FROM `#__update_sites_extensions`
                           WHERE extension_id=' . (int)$extension->extension_id);
            $db->execute();

            $db->setQuery('DELETE FROM `#__update_sites`
                           WHERE update_site_id IN (' . join(',', $list) . ')');
            $db->execute();
        }
    }

    /**
     * Get extension information from manifest
     *
     * @return JRegistry
     */
    protected function getInfoFromManifest($manifestPath)
    {
        $info = new JRegistry();
        if (file_exists($manifestPath)) {
            $xml = JFactory::getXML($manifestPath);

            $attributes = (array) $xml->attributes();
            $attributes = $attributes['@attributes'];
            foreach ($attributes as $attribute => $value) {
                $info->set($attribute, $value);
            }

            foreach ($xml->children() as $e) {
                if (!$e->children()) {
                    $info->set($e->getName(), (string)$e);
                }
            }
        }

        return $info;
    }

    /**
     * Get the path for the extension
     *
     * @return string The path
     */
    public function getManifestPath($type, $element, $group = '')
    {
        $basePath = '';
        $manifestPath = '';

        $folders = array(
            'component' => 'administrator/components/',
            'plugin'    => 'plugins/',
            'template'  => 'templates/',
            'library'   => 'administrator/manifests/libraries/',
            'cli'       => 'cli/',
            'module'    => 'modules/'
        );

        $basePath = JPATH_SITE . '/' . $folders[$type];

        if ($type === 'plugin') {
            $basePath .= $group . '/' . $element;
        } elseif ($type === 'module') {
            $basePath .= 'mod_' . $element;
        } else {
            $basePath .= $element;
        }

        $installer = new JInstaller();
        if ($type !== 'library') {
            $installer->setPath('source', $basePath);
            $installer->getManifest();

            $manifestPath = $installer->getPath('manifest');
        } else {
            $manifestPath = $basePath . '.xml';

            if (!file_exists($manifestPath)) {
                $manifestPath = str_replace($element, 'lib_' . $element, $basePath) . '.xml';
            }

            if (!$installer->isManifest($manifestPath)) {
                $manifestPath = '';
            }
        }

        return $manifestPath;
    }
}
