<?php
/*
 * Wolf CMS - Content Management Simplified. <http://www.wolfcms.org>
 * Copyright (C) 2008,2009,2010 Martijn van der Kleijn <martijn.niji@gmail.com>
 * Copyright (C) 2008 Philippe Archambault <philippe.archambault@gmail.com>
 *
 * This file is part of Wolf CMS.
 *
 * Wolf CMS is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Wolf CMS is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Wolf CMS.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Wolf CMS has made an exception to the GNU General Public License for plugins.
 * See exception.txt for details and the full text.
 */

/**
 * @package wolf
 * @subpackage controllers
 *
 * @author Martijn van der Kleijn <martijn.niji@gmail.com>
 * @author Philippe Archambault <philippe.archambault@gmail.com>
 * @version 0.1
 * @license http://www.gnu.org/licenses/gpl.html GPL License
 * @copyright Martijn van der Kleijn, 2008, 2009, 2010
 * @copyright Philippe Archambault, 2008
 */

/**
 * Class PagesController
 *
 * @package wolf
 * @subpackage controllers
 *
 * @since 0.1
 */
class PageController extends Controller {
    public function __construct() {
        AuthUser::load();
        if ( ! AuthUser::isLoggedIn())
            redirect(get_url('login'));
    }

    public function index() {
        $this->setLayout('backend');
        $this->display('page/index', array(
            'root' => Record::findByIdFrom('Page', 1),
            'content_children' => $this->children(1, 0, true)
        ));
    }

    /**
     * Action to add a page.
     *
     * @param int $parent_id The page id for the new page's parent. Defaults to page 1.
     * @return <type>
     */
    public function add($parent_id=1) {
        // Check if trying to save.
        if (get_request_method() == 'POST')
            return $this->_store('add');

        // If not trying to save, display "Add page" view.
        $data = Flash::get('post_data');
        $page = new Page($data);
        $page->parent_id = $parent_id;
        $page->status_id = Setting::get('default_status_id');
        $page->needs_login = Page::LOGIN_INHERIT;

        $page_parts = Flash::get('post_parts_data');

        if (empty($page_parts)) {
            // Check if we have a big sister.
            $big_sister = Record::findOneFrom('Page', 'parent_id=? ORDER BY id DESC', array($parent_id));
            if ($big_sister) {
                // Get list of parts create the same for the new little sister
                $big_sister_parts = Record::findAllFrom('PagePart', 'page_id=? ORDER BY id', array($big_sister->id));
                $page_parts = array();
                foreach ($big_sister_parts as $parts) {
                    $page_parts[] = new PagePart(array(
                        'name' => $parts->name,
                        'filter_id' => Setting::get('default_filter_id')
                    ));
                }
            }
            else {
                $page_parts = array(new PagePart(array('filter_id' => Setting::get('default_filter_id'))));
            }
        }

        // Display actual view.
        $this->setLayout('backend');
        $this->display('page/edit', array(
            'action'     => 'add',
            'page'       => $page,
            'tags'       => array(),
            'filters'    => Filter::findAll(),
            'behaviors'  => Behavior::findAll(),
            'page_parts' => $page_parts,
            'layouts'    => Record::findAllFrom('Layout'))
        );
    }

    /**
     * Ajax action to add a part.
     */
    public function addPart() {
        header('Content-Type: text/html; charset: utf-8');

        $data = isset($_POST['part']) ? $_POST['part']: array();
        $data['name'] = isset($data['name']) ? trim($data['name']): '';
        $data['index'] = isset($data['index']) ? $data['index']: 1;

        echo $this->_getPartView($data['index'], $data['name']);
    }

    /**
     * Action to edit a page.
     *
     * @aram int $id Page ID for page to edit.
     * @return <type>
     */
    public function edit($id) {
        if (!is_numeric($id)) {
            redirect(get_url('page'));
        }

        // Check if trying to save.
        if (get_request_method() == 'POST')
            return $this->_store('edit', $id);

        $page = Page::findById($id);

        if ( ! $page) {
            Flash::set('error', __('Page not found!'));
            redirect(get_url('page'));
        }

        // check for protected page and editor user
        if ( ! AuthUser::hasPermission('administrator') && ! AuthUser::hasPermission('developer') && $page->is_protected) {
            Flash::set('error', __('You do not have permission to access the requested page!'));
            redirect(get_url('page'));
        }

		// encode the quotes to prevent page title input break
		$page->title = htmlentities($page->title, ENT_QUOTES);

        // find all page_part of this pages
        $page_parts = PagePart::findByPageId($id);

        if (empty($page_parts))
            $page_parts = array(new PagePart);

        // display things ...
        $this->setLayout('backend');
        $this->display('page/edit', array(
            'action'     => 'edit',
            'page'       => $page,
            'tags'       => $page->getTags(),
            'filters'    => Filter::findAll(),
            'behaviors'  => Behavior::findAll(),
            'page_parts' => $page_parts,
            'layouts'    => Record::findAllFrom('Layout', '1=1 ORDER BY position'))
        );
    }

    /**
     * Used to delete a page.
     *
     * @todo make sure we not only delete the page but also all parts and all children!
     *
     * @param int $id Id of page to delete
     */
    public function delete($id) {
    // security (dont delete the root page)
        if ($id > 1) {
        // find the page to delete
            if ($page = Record::findByIdFrom('Page', $id)) {
            // check for permission to delete this page
                if ( ! AuthUser::hasPermission('administrator') && ! AuthUser::hasPermission('developer') && $page->is_protected) {
                    Flash::set('error', __('You do not have permission to access the requested page!'));
                    redirect(get_url('page'));
                }

                // need to delete all page_parts too !!
                PagePart::deleteByPageId($id);

                if ($page->delete()) {
                    Observer::notify('page_delete', $page);
                    Flash::set('success', __('Page :title has been deleted!', array(':title'=>$page->title)));
                }
                else Flash::set('error', __('Page :title has not been deleted!', array(':title'=>$page->title)));
            }
            else Flash::set('error', __('Page not found!'));
        }
        else Flash::set('error', __('Action disabled!'));

        redirect(get_url('page'));
    }

    /**
     * Action to return a list View of all first level children of a page.
     *
     * @todo improve phpdoc desc
     *
     * @param <type> $parent_id
     * @param <type> $level
     * @param <type> $return
     * @return View
     */
    function children($parent_id, $level, $return=false) {
        $expanded_rows = isset($_COOKIE['expanded_rows']) ? explode(',', $_COOKIE['expanded_rows']): array();

        // get all children of the page (parent_id)
        $childrens = Page::childrenOf($parent_id);

        foreach ($childrens as $index => $child) {
            $childrens[$index]->has_children = Page::hasChildren($child->id);
            $childrens[$index]->is_expanded = in_array($child->id, $expanded_rows);

            if ($childrens[$index]->is_expanded)
                $childrens[$index]->children_rows = $this->children($child->id, $level+1, true);
        }

        $content = new View('page/children', array(
            'childrens' => $childrens,
            'level'    => $level+1,
        ));

        if ($return)
            return $content;

        echo $content;
    }

    /**
     * Ajax action to reorder (page->position) a page.
     *
     * All the children of the new page->parent_id have to be updated
     * and all nested tree have to be rebuild.
     *
     * @param <type> $parent_id
     */
    function reorder($parent_id) {
        //throw new Exception('TEST-'.print_r($_POST['data'], true));
        parse_str($_POST['data']);

        foreach ($pages as $position => $page_id) {
            $page = Record::findByIdFrom('Page', $page_id);
            $page->position = (int) $position;
            $page->parent_id = (int) $parent_id;
            $page->save();

        }
    }

    /**
     * Ajax action to copy a page or page tree.
     *
     * @param <type> $parent_id
     */
    function copy($parent_id) {
        parse_str($_POST['data']);

        $page = Record::findByIdFrom('Page', $dragged_id);
        $new_root_id = Page::cloneTree($page, $parent_id);

        foreach ($pages as $position => $page_id) {
            if ($page_id == $dragged_id) {
                /* Move the cloned tree, not original. */
                $page = Record::findByIdFrom('Page', $new_root_id);
            } else {
                $page = Record::findByIdFrom('Page', $page_id);
            }
            $page->position = (int)$position;
            $page->parent_id = (int)$parent_id;
            $page->save();

        }

    }


    //  Private methods  -----------------------------------------------------

    /**
     *
     * @param <type> $index
     * @param <type> $name
     * @param <type> $filter_id
     * @param <type> $content
     * @return <type>
     */
    private function _getPartView($index=1, $name='', $filter_id='', $content='') {
        $page_part = new PagePart(array(
            'name' => $name,
            'filter_id' => $filter_id,
            'content' => $content)
        );

        return $this->render('page/part_edit', array(
        'index'     => $index,
        'page_part' => $page_part
        ));
    }

    /**
     * Runs checks and stores a page.
     *
     * @param string $action   What kind of action this is: add or edit.
     * @param mixed $id        Page to edit if any.
     */
    private function _store($action, $id=false) {
        // Sanity check
        if ($action == 'edit' && !$id)
            throw new Exception ('Trying to edit page when $id is false.');

        $data = $_POST['page'];
        $data['is_protected'] = !empty($data['is_protected']) ? 1: 0;
        Flash::set('post_data', (object) $data);

        // Add pre-save checks here
        $errors = false;

        if (empty($data['title'])) {
            $errors[] = __('You have to specify a title!');
        }

        if (empty($data['slug'])) {
            $errors[] = __('You have to specify a slug!');
        }
        else {
            if (trim($data['slug']) == ADMIN_DIR) {
                $errors[] = __('You cannot have a slug named :slug!', array(':slug' => ADMIN_DIR));
            }
        }

        // Make sure the title doesn't contain HTML
        if (Setting::get('allow_html_title') == 'off') {
            use_helper('Kses');
            $data['title'] = kses(trim($data['title']), array());
        }

        // Create the page object to be manipulated and populate data
        if ($action == 'add') {
            $page = new Page($data);
        }
        else {
            $page = Record::findByIdFrom('Page', $id);
            $page->setFromData($data);
        }

        // Upon errors, rebuild original page and return to screen with errors
        if (false !== $errors) {
            $tags = $_POST['page_tag'];

            // Rebuild parts
            $part = $_POST['part'];
            if (!empty($part)) {
                $tmp = false;
                foreach ($part as $key => $val) {
                    $tmp[$key] = (object) $val;
                }
                $part = $tmp;
            }

            // Set the errors to be displayed.
            Flash::setNow('error', implode('<br/>', $errors));

            // display things ...
            $this->setLayout('backend');
            $this->display('page/edit', array(
                'action'     => $action,
                'page'       => (object) $page,
                'tags'       => $tags,
                'filters'    => Filter::findAll(),
                'behaviors'  => Behavior::findAll(),
                'page_parts' => (object) $part,
                'layouts'    => Record::findAllFrom('Layout'))
            );
        }

        // Notify
        if ($action == 'add') {
            Observer::notify('page_add_before_save', $page);
        }
        else {
            Observer::notify('page_edit_before_save', $page);
        }

        // Time to actually save the page
        // @todo rebuild this so parts are already set before save?
        // @todo determine lazy init impact
        if ($page->save()) {
            // Get data for parts of this page
            $data_parts = $_POST['part'];
            Flash::set('post_parts_data', (object) $data_parts);

            if ($action == 'edit') {
                $old_parts = PagePart::findByPageId($id);

                // check if all old page part are passed in POST
                // if not ... we need to delete it!
                foreach ($old_parts as $old_part) {
                    $not_in = true;
                    foreach ($data_parts as $part_id => $data) {
                        $data['name'] = trim($data['name']);
                        if ($old_part->name == $data['name']) {
                            $not_in = false;

                            // this will not really create a new page part because
                            // the id of the part is passed in $data
                            $part = new PagePart($data);
                            $part->page_id = $id;

                            Observer::notify('part_edit_before_save', $part);
                            $part->save();
                            Observer::notify('part_edit_after_save', $part);

                            unset($data_parts[$part_id]);

                            break;
                        }
                    }

                    if ($not_in)
                        $old_part->delete();
                }
            }

            // add the new parts
            foreach ($data_parts as $data) {
                $data['name'] = trim($data['name']);
                $part = new PagePart($data);
                $part->page_id = $page->id;
                Observer::notify('part_add_before_save', $part);
                $part->save();
                Observer::notify('part_add_after_save', $part);
            }

            // save tags
            $page->saveTags($_POST['page_tag']['tags']);

            Flash::set('success', __('Page has been saved!'));
        }
        else {
            Flash::set('error', __('Page has not been saved!'));
            echo 'TEST1';
            redirect(get_url('page'.($action == 'edit') ? 'edit/'.$id : 'add/'));
        }

        if ($action == 'add') {
            Observer::notify('page_add_after_save', $page);
        }
        else {
            Observer::notify('page_edit_after_save', $page);
        }

        // save and quit or save and continue editing ?
        if (isset($_POST['commit'])) {
            redirect(get_url('page'));
        }
        else {
            redirect(get_url('page/edit/'.$page->id));
        }
    }

} // end PageController class
