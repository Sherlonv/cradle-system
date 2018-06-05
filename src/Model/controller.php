<?php //-->
/**
 * This file is part of a package designed for the CradlePHP Project.
 *
 * Copyright and license information can be found at LICENSE.txt
 * distributed with this package.
 */

use Cradle\Module\Utility\File;
use Cradle\Package\System\Schema;

use Cradle\Http\Request;
use Cradle\Http\Response;

//Back End Controllers

/**
 * Render the System Model Search Page
 *
 * @param Request $request
 * @param Response $response
 */
$this->get('/admin/system/model/:schema/search', function ($request, $response) {
    //----------------------------//
    // 1. Prepare Data
    //get schema data
    $schema = Schema::i($request->getStage('schema'));

    //if this is a return back from processing
    //this form and it's because of an error
    if ($response->isError()) {
        //pass the error messages to the template
        $response->setFlash($response->getMessage(), 'error');
    }

    //set a default range
    if (!$request->hasStage('range')) {
        $request->setStage('range', 50);
    }

    //filter possible filter options
    //we do this to prevent SQL injections
    if (is_array($request->getStage('filter'))) {
        foreach ($request->getStage('filter') as $key => $value) {
            //if invalid key format or there is no value
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $key) || !strlen($value)) {
                $request->removeStage('filter', $key);
            }
        }
    }

    //filter possible sort options
    //we do this to prevent SQL injections
    if (is_array($request->getStage('order'))) {
        foreach ($request->getStage('order') as $key => $value) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                $request->removeStage('order', $key);
            }
        }
    }

    //trigger job
    $this->trigger('system-model-search', $request, $response);

    //if we only want the raw data
    if ($request->getStage('render') === 'false') {
        return;
    }

    //form the data
    $data = array_merge(
        //we need to case for things like
        //filter and sort on the template
        $request->getStage(),
        //this is from the search event
        $response->getResults()
    );

    //also pass the schema to the template
    $data['schema'] = $schema->getAll();

    //if there's an active field
    if ($data['schema']['active']) {
        //find it
        foreach ($data['schema']['filterable'] as $i => $filter) {
            //if we found it
            if ($filter === $data['schema']['active']) {
                //remove it from the filters
                unset($data['schema']['filterable'][$i]);
            }
        }

        //reindex filterable
        $data['schema']['filterable'] = array_values($data['schema']['filterable']);
    }

    $data['filterable_relations'] = [];
    foreach ($data['schema']['relations'] as $relation) {
        if ($relation['many'] < 2) {
            $data['filterable_relations'][] = $relation;
        }
    }

    //determine valid relations
    $data['valid_relations'] = [];

    //if there's active filter, get its value
    //for search purposes
    if (isset($data['filter'])) {
        $data['filter']['active'] = null;

        foreach($data['filter'] as $filter => $value) {
            if ($filter === $data['schema']['active']) {
                $data['filter']['active'] = $value;
            }
        }
    }

    $this->trigger('system-schema-search', $request, $response);

    foreach ($response->getResults('rows') as $relation) {
        $data['valid_relations'][] = $relation['name'];
    }

    // execute webhook distribution
    try {
        $webhook = [
            'uri' => '/admin/system/model/:schema/search',
            'method' => 'get',
            'json_data' => json_encode($data)
        ];

        $this
            ->package('cradlephp/cradle-queue')
            ->queue('webhook-distribution', $webhook);
    } catch (Exception $e) {
    }
    //----------------------------//
    // 2. Render Template
    //set the class name
    $class = 'page-admin-system-model-search page-admin';

    //render the body
    $body = $this
        ->package('cradlephp/cradle-system')
        ->template('model', 'search', $data, [
            'search_head',
            'search_form',
            'search_filters',
            'search_actions',
            'search_row_format',
            'search_row_actions'
        ]);

    //set content
    $response
        ->setPage('title', $data['schema']['plural'])
        ->setPage('class', $class)
        ->setContent($body);

    //if we only want the body
    if ($request->getStage('render') === 'body') {
        return;
    }

    //render page
    $this->trigger('admin-render-page', $request, $response);
});

/**
 * Render the System Model Create Page
 *
 * @param Request $request
 * @param Response $response
 */
$this->get('/admin/system/model/:schema/create', function ($request, $response) {
    //----------------------------//
    // 1. Prepare Data
    //get schema data
    $schema = Schema::i($request->getStage('schema'));

    //pass the item with only the post data
    $data = ['item' => $request->getPost()];

    //also pass the schema to the template
    $data['schema'] = $schema->getAll();

    //if this is a return back from processing
    //this form and it's because of an error
    if ($response->isError()) {
        //pass the error messages to the template
        $response->setFlash($response->getMessage(), 'error');
        $data['errors'] = $response->getValidation();
    }

    //for ?copy=1 functionality
    if (empty($data['item']) && is_numeric($request->getStage('copy'))) {
        //table_id, 1 for example
        $request->setStage(
            $schema->getPrimaryFieldName(),
            $request->getStage('copy')
        );

        //get the original table row
        $this->trigger('system-model-detail', $request, $response);

        //can we update ?
        if ($response->isError()) {
            //add a flash
            $this->package('global')->flash($response->getMessage(), 'error');
            return $this->package('global')->redirect(sprintf(
                '/admin/system/schema/%s/search',
                $request->getStage('schema')
            ));
        }

        //pass the item to the template
        $data['item'] = $response->getResults();

        //add suggestion value for each relation
        foreach ($data['schema']['relations'] as $name => $relation) {
            if ($relation['many'] > 1) {
                continue;
            }

            $suggestion = '_' . $relation['primary2'];

            $suggestionData = $data['item'];
            if ($relation['many'] == 0) {
                if (!isset($data['item'][$relation['name']])) {
                    continue;
                }

                $suggestionData = $data['item'][$relation['name']];

                if (!$suggestionData) {
                    continue;
                }
            }

            try {
                $data['item'][$suggestion] = Schema::i($relation['name'])
                    ->getSuggestionFormat($suggestionData);
            } catch (Exception $e) {
            }
        }
    }

    //add CSRF
    $this->trigger('csrf-load', $request, $response);
    $data['csrf'] = $response->getResults('csrf');

    //if there are file fields
    if (!empty($data['schema']['files'])) {
        //add CDN
        $config = $this->package('global')->service('s3-main');
        $data['cdn_config'] = File::getS3Client($config);
    }

    //if this is a relational process
    if ($request->hasStage('relation')) {
        //also pass the relation to the form
        $data['relation'] = $request->getStage('relation');
    }

    //determine valid relations
    $data['valid_relations'] = [];
    $this->trigger('system-schema-search', $request, $response);
    foreach ($response->getResults('rows') as $relation) {
        $data['valid_relations'][] = $relation['name'];
    }

    //if we only want the data
    if ($request->getStage('render') === 'false') {
        return $response->setJson($data);
    }

    // execute webhook distribution
    try {
        $webhook = [
            'uri' => '/admin/system/model/:schema/create',
            'method' => 'get',
            'json_data' => json_encode($data)
        ];

        $this
            ->package('cradlephp/cradle-queue')
            ->queue('webhook-distribution', $webhook);
    } catch (Exception $e) {
    }

    //----------------------------//
    // 2. Render Template
    //set the class name
    $class = 'page-admin-system-model-create page-admin';

    //set the action
    $data['action'] = 'create';

    //determine the title
    $data['title'] = $this->package('global')->translate(
        'Create %s',
        $data['schema']['singular']
    );

    //add custom page helpers
    $this->package('global')
        ->handlebars()
        ->registerHelper('json_encode', function (...$args) {
            $options = array_pop($args);
            $value = array_shift($args);

            foreach ($args as $arg) {
                if (!isset($value[$arg])) {
                    $value = null;
                    break;
                }

                $value = $value[$arg];
            }

            if (!$value) {
                return '';
            }

            return json_encode($value, JSON_PRETTY_PRINT);
        });

    //render the body
    $body = $this
        ->package('cradlephp/cradle-system')
        ->template('model', 'form', $data, [
            'form_fields',
            'form_detail',
            'form_format',
        ]);

    //set content
    $response
        ->setPage('title', $data['title'])
        ->setPage('class', $class)
        ->setContent($body);

    //if we only want the body
    if ($request->getStage('render') === 'body') {
        return;
    }

    //render page
    $this->trigger('admin-render-page', $request, $response);
});

/**
 * Render the System Model Update Page
 *
 * @param Request $request
 * @param Response $response
 */
$this->get('/admin/system/model/:schema/update/:id', function ($request, $response) {
    //----------------------------//
    // 1. Prepare Data
    //get schema data
    $schema = Schema::i($request->getStage('schema'));

    //pass the item with only the post data
    $data = ['item' => $request->getPost()];

    //also pass the schema to the template
    $data['schema'] = $schema->getAll();

    //if this is a return back from processing
    //this form and it's because of an error
    if ($response->isError()) {
        //pass the error messages to the template
        $response->setFlash($response->getMessage(), 'error');
        $data['errors'] = $response->getValidation();
    }

    //table_id, 1 for example
    $request->setStage(
        $schema->getPrimaryFieldName(),
        $request->getStage('id')
    );

    //get the original table row
    $this->trigger('system-model-detail', $request, $response);

    //can we update ?
    if ($response->isError()) {
        //redirect
        $redirect = sprintf(
            '/admin/system/model/%s/search',
            $request->getStage('schema')
        );

        //this is for flexibility
        if ($request->hasStage('redirect_uri')) {
            $redirect = $request->getStage('redirect_uri');
        }

        //add a flash
        $this->package('global')->flash($response->getMessage(), 'error');
        return $this->package('global')->redirect($redirect);
    }

    $data['detail'] = $response->getResults();

    //if no item
    if (empty($data['item'])) {
        //pass the item to the template
        $data['item'] = $data['detail'];

        //add suggestion value for each relation
        foreach ($data['schema']['relations'] as $name => $relation) {
            if ($relation['many'] > 1) {
                continue;
            }

            $suggestion = '_' . $relation['primary2'];

            $suggestionData = $data['item'];
            if ($relation['many'] == 0) {
                if (!isset($data['item'][$relation['name']])) {
                    continue;
                }

                $suggestionData = $data['item'][$relation['name']];

                if (!$suggestionData) {
                    continue;
                }
            }

            try {
                $data['item'][$suggestion] = Schema::i($relation['name'])
                    ->getSuggestionFormat($suggestionData);
            } catch (Exception $e) {
            }
        }
    }

    //if we only want the raw data
    if ($request->getStage('render') === 'false') {
        return;
    }

    //determine the suggestion
    $data['detail']['suggestion'] = $schema->getSuggestionFormat($data['item']);

    //add CSRF
    $this->trigger('csrf-load', $request, $response);
    $data['csrf'] = $response->getResults('csrf');

    //if there are file fields
    if (!empty($data['schema']['files'])) {
        //add CDN
        $config = $this->package('global')->service('s3-main');
        $data['cdn_config'] = File::getS3Client($config);
    }

    //determine valid relations
    $data['valid_relations'] = [];
    $this->trigger('system-schema-search', $request, $response);
    foreach ($response->getResults('rows') as $relation) {
        $data['valid_relations'][] = $relation['name'];
    }

    $data['redirect'] = urlencode($request->getServer('REQUEST_URI'));

    // execute webhook distribution
    try {
        $webhook = [
            'uri' => '/admin/system/model/:schema/update/:id',
            'method' => 'get',
            'json_data' => json_encode($data)
        ];

        $this
            ->package('cradlephp/cradle-queue')
            ->queue('webhook-distribution', $webhook);
    } catch (Exception $e) {
    }

    //----------------------------//
    // 2. Render Template
    //set the class name
    $class = 'page-admin-system-model-update page-admin';

    //set the action
    $data['action'] = 'update';

    //determine the title
    $data['title'] = $this->package('global')->translate(
        'Updating %s',
        $data['schema']['singular']
    );

    //add custom page helpers
    $this->package('global')
        ->handlebars()
        ->registerHelper('json_encode', function (...$args) {
            $options = array_pop($args);
            $value = array_shift($args);
            foreach ($args as $arg) {
                if (!isset($value[$arg])) {
                    $value = null;
                    break;
                }

                $value = $value[$arg];
            }

            if (!$value) {
                return '';
            }

            return json_encode($value, JSON_PRETTY_PRINT);
        });

    //render the body
    $body = $this
        ->package('cradlephp/cradle-system')
        ->template('model', 'form', $data, [
            'form_fields',
            'form_detail',
            'form_format',
        ]);

    //set content
    $response
        ->setPage('title', $data['title'])
        ->setPage('class', $class)
        ->setContent($body);

    //if we only want the body
    if ($request->getStage('render') === 'body') {
        return;
    }

    //render page
    $this->trigger('admin-render-page', $request, $response);
});

/**
 * Render the System Model Update Page
 *
 * @param Request $request
 * @param Response $response
 */
$this->get('/admin/system/model/:schema/detail/:id', function ($request, $response) {
    //----------------------------//
    // 1. Prepare Data
    //get schema data
    $schema = Schema::i($request->getStage('schema'));

    //pass the item with only the post data
    $data = ['item' => $request->getPost()];

    //also pass the schema to the template
    $data['schema'] = $schema->getAll();

    //if this is a return back from processing
    //this form and it's because of an error
    if ($response->isError()) {
        //pass the error messages to the template
        $response->setFlash($response->getMessage(), 'error');
        $data['errors'] = $response->getValidation();
    }

    //table_id, 1 for example
    $request->setStage(
        $schema->getPrimaryFieldName(),
        $request->getStage('id')
    );

    //get the original table row
    $this->trigger('system-model-detail', $request, $response);

    //can we update ?
    if ($response->isError()) {
        //redirect
        $redirect = sprintf(
            '/admin/system/model/%s/search',
            $request->getStage('schema')
        );

        //this is for flexibility
        if ($request->hasStage('redirect_uri')) {
            $redirect = $request->getStage('redirect_uri');
        }

        //add a flash
        $this->package('global')->flash($response->getMessage(), 'error');
        return $this->package('global')->redirect($redirect);
    }

    $data['detail'] = $response->getResults();

    //if no item
    if (empty($data['item'])) {
        //pass the item to the template
        $data['item'] = $data['detail'];

        //add suggestion value for each relation
        foreach ($data['schema']['relations'] as $name => $relation) {
            if ($relation['many'] > 1) {
                continue;
            }

            $suggestion = '_' . $relation['primary2'];

            $suggestionData = $data['item'];
            if ($relation['many'] == 0) {
                if (!isset($data['item'][$relation['name']])) {
                    continue;
                }

                $suggestionData = $data['item'][$relation['name']];

                if (!$suggestionData) {
                    continue;
                }
            }

            try {
                $data['item'][$suggestion] = Schema::i($relation['name'])
                    ->getSuggestionFormat($suggestionData);
            } catch (Exception $e) {
            }
        }
    }

    //if we only want the raw data
    if ($request->getStage('render') === 'false') {
        return;
    }

    //determine the suggestion
    $data['detail']['suggestion'] = $schema->getSuggestionFormat($data['item']);

    //add CSRF
    $this->trigger('csrf-load', $request, $response);
    $data['csrf'] = $response->getResults('csrf');

    //if there are file fields
    if (!empty($data['schema']['files'])) {
        //add CDN
        $config = $this->package('global')->service('s3-main');
        $data['cdn_config'] = File::getS3Client($config);
    }

    //determine valid relations
    $data['valid_relations'] = [];
    $this->trigger('system-schema-search', $request, $response);
    foreach ($response->getResults('rows') as $relation) {
        $data['valid_relations'][] = $relation['name'];
    }

    $data['redirect'] = urlencode($request->getServer('REQUEST_URI'));

    // execute webhook distribution
    try {
        $webhook = [
            'uri' => '/admin/system/model/:schema/detail/:id',
            'method' => 'get',
            'json_data' => json_encode($data)
        ];

        $this
            ->package('cradlephp/cradle-queue')
            ->queue('webhook-distribution', $webhook);
    } catch (Exception $e) {
    }

    //----------------------------//
    // 2. Render Template
    //set the class name
    $class = 'page-admin-system-model-update page-admin';

    //set the action
    $data['action'] = 'detail';

    // get the suggestion title
    $suggestion = str_replace('{', '', $data['schema']['suggestion']);
    $suggestion = str_replace('}', '', $suggestion);

    //determine the title
    $data['title'] = $this->package('global')->translate(
        '%s Detail',
        $data['item'][$suggestion]
    );

    //add custom page helpers
    $this->package('global')
        ->handlebars()
        ->registerHelper('json_encode', function (...$args) {
            $options = array_pop($args);
            $value = array_shift($args);
            foreach ($args as $arg) {
                if (!isset($value[$arg])) {
                    $value = null;
                    break;
                }

                $value = $value[$arg];
            }

            if (!$value) {
                return '';
            }

            return json_encode($value, JSON_PRETTY_PRINT);
        });

    //render the body
    $body = $this
        ->package('cradlephp/cradle-system')
        ->template('model', 'form', $data, [
            'form_detail',
            'form_format',
        ]);

    //set content
    $response
        ->setPage('title', $data['title'])
        ->setPage('class', $class)
        ->setContent($body);

    //if we only want the body
    if ($request->getStage('render') === 'body') {
        return;
    }

    //render page
    $this->trigger('admin-render-page', $request, $response);
});

/**
 * Process the System Model Search Actions
 *
 * @param Request $request
 * @param Response $response
 */
$this->post('/admin/system/model/:schema/search', function ($request, $response) {
    //----------------------------//
    // 1. Prepare Data
    //get schema data
    $schema = Schema::i($request->getStage('schema'));

    //determine route
    $route = sprintf(
        '/admin/system/model/%s/search',
        $request->getStage('schema')
    );

    //this is for flexibility
    if ($request->hasStage('route')) {
        $route = $request->getStage('route');
    }

    $action = $request->getStage('bulk-action');
    $ids = $request->getStage($schema->getPrimaryFieldName());

    if (empty($ids)) {
        $response->setError(true, 'No IDs chosen');
        //let the form route handle the rest
        return $this->routeTo('get', $route, $request, $response);
    }

    //----------------------------//
    // 2. Process Request
    $errors = [];
    foreach ($ids as $id) {
        //table_id, 1 for example
        $request->setStage($schema->getPrimaryFieldName(), $id);

        //case for actions
        switch ($action) {
            case 'remove':
                $this->trigger('system-model-remove', $request, $response);
                break;
            case 'restore':
                $this->trigger('system-model-restore', $request, $response);
                break;
            default:
                //set an error
                $response->setError(true, 'No valid action chosen');
                //let the search route handle the rest
                return $this->routeTo('get', $route, $request, $response);
        }

        if ($response->isError()) {
            $errors[] = $response->getMessage();
        } else {
            $this->log(
                sprintf(
                    '%s #%s %s',
                    $schema->getSingular(),
                    $id,
                    $action
                ),
                $request,
                $response
            );
        }
    }

    //----------------------------//
    // 3. Interpret Results
    //redirect
    $redirect = sprintf(
        '/admin/system/model/%s/search',
        $schema->getName()
    );

    //if there is a specified redirect
    if ($request->getStage('redirect_uri')) {
        //set the redirect
        $redirect = $request->getStage('redirect_uri');
    }

    //if we dont want to redirect
    if ($redirect === 'false') {
        return;
    }

    //add a flash
    if (!empty($errors)) {
        $this->package('global')->flash(
            'Some items could not be processed',
            'error',
            $errors
        );
    } else {
        $this->package('global')->flash(
            sprintf(
                'Bulk action %s successful',
                $action
            ),
            'success'
        );
    }

    $this->package('global')->redirect($redirect);
});

/**
 * Process the System Model Create Page
 *
 * @param Request $request
 * @param Response $response
 */
$this->post('/admin/system/model/:schema/create', function ($request, $response) {
    //----------------------------//
    // 1. Prepare Data
    //get schema data
    $schema = Schema::i($request->getStage('schema'));

    //get all the schema field data
    $fields = $schema->getFields();

    //these are invalid types to set
    $invalidTypes = ['none', 'active', 'created', 'updated'];

    //for each field
    foreach ($fields as $name => $field) {
        //if the field is invalid
        if (in_array($field['field']['type'], $invalidTypes)) {
            $request->removeStage($name);
            continue;
        }

        //if no value
        if ($request->hasStage($name) && !$request->getStage($name)) {
            //make it null
            $request->setStage($name, null);
            continue;
        }

        if (//if there is a default
            isset($field['default'])
            && trim($field['default'])
            // and there's no stage
            && $request->hasStage($name)
            && !$request->getStage($name)
        ) {
            if (strtoupper($field['default']) === 'NOW()') {
                $field['default'] = date('Y-m-d H:i:s');
            }

            //set the default
            $request->setStage($name, $field['default']);
            continue;
        }
    }

    //----------------------------//
    // 2. Process Request
    $this->trigger('system-model-create', $request, $response);

    //----------------------------//
    // 3. Interpret Results
    //if the event returned an error
    if ($response->isError()) {
        //determine route
        $route = sprintf(
            '/admin/system/model/%s/create',
            $request->getStage('schema')
        );

        //this is for flexibility
        if ($request->hasStage('route')) {
            $route = $request->getStage('route');
        }

        //let the form route handle the rest
        return $this->routeTo('get', $route, $request, $response);
    }

    //it was good

    //record logs
    $this->log(
        sprintf(
            'New %s created',
            $schema->getSingular()
        ),
        $request,
        $response
    );

    //redirect
    $redirect = sprintf(
        '/admin/system/model/%s/search',
        $schema->getName()
    );

    //if there is a specified redirect
    if ($request->getStage('redirect_uri')) {
        //set the redirect
        $redirect = $request->getStage('redirect_uri');
    }

    //if we dont want to redirect
    if ($redirect === 'false') {
        return;
    }

    // execute webhook distribution
    try {
        $webhook = [
            'uri' => '/admin/system/model/:schema/create',
            'method' => 'post',
            'json_data' => json_encode($response->getResults())
        ];

        $this
            ->package('cradlephp/cradle-queue')
            ->queue('webhook-distribution', $webhook);
    } catch (Exception $e) {
    }

    //add a flash
    $this->package('global')->flash(sprintf(
        '%s was Created',
        $schema->getSingular()
    ), 'success');

    $this->package('global')->redirect($redirect);
});

/**
 * Process the System Model Update Page
 *
 * @param Request $request
 * @param Response $response
 */
$this->post('/admin/system/model/:schema/update/:id', function ($request, $response) {
    //----------------------------//
    // 1. Prepare Data
    //get schema data
    $schema = Schema::i($request->getStage('schema'));

    //table_id, 1 for example
    $request->setStage(
        $schema->getPrimaryFieldName(),
        $request->getStage('id')
    );

    //get all the schema field data
    $fields = $schema->getFields();

    //these are invalid types to set
    $invalidTypes = ['none', 'active', 'created', 'updated'];

    //for each field
    foreach ($fields as $name => $field) {
        //if the field is invalid
        if (in_array($field['field']['type'], $invalidTypes)) {
            $request->removeStage($name);
            continue;
        }

        //if password has no value
        if ($request->hasStage($name) && !$request->getStage($name)
            && $field['field']['type'] === 'password'
        ) {
            //make it null
            $request->removeStage($name);
            continue;
        }

        //if no value
        if ($request->hasStage($name) && !$request->getStage($name)) {
            //make it null
            $request->setStage($name, null);
            continue;
        }

        if (//if there is a default
            isset($field['default'])
            && trim($field['default'])
            // and there's no stage
            && $request->hasStage($name)
            && !$request->getStage($name)
        ) {
            if (strtoupper($field['default']) === 'NOW()') {
                $field['default'] = date('Y-m-d H:i:s');
            }

            //set the default
            $request->setStage($name, $field['default']);
            continue;
        }
    }

    //----------------------------//
    // 2. Process Request
    $this->trigger('system-model-update', $request, $response);

    //----------------------------//
    // 3. Interpret Results
    //if the event returned an error
    if ($response->isError()) {
        //determine route
        $route = sprintf(
            '/admin/system/model/%s/update/%s',
            $request->getStage('schema'),
            $request->getStage('id')
        );

        //this is for flexibility
        if ($request->hasStage('route')) {
            $route = $request->getStage('route');
        }

        //let the form route handle the rest
        return $this->routeTo('get', $route, $request, $response);
    }

    //it was good

    //record logs
    $this->log(
        sprintf(
            '%s #%s updated',
            $schema->getSingular(),
            $request->getStage('id')
        ),
        $request,
        $response
    );

    //redirect
    $redirect = sprintf(
        '/admin/system/model/%s/search',
        $schema->getName()
    );

    //if there is a specified redirect
    if ($request->getStage('redirect_uri')) {
        //set the redirect
        $redirect = $request->getStage('redirect_uri');
    }

    //if we dont want to redirect
    if ($redirect === 'false') {
        return;
    }

    // execute webhook distribution
    try {
        $webhook = [
            'uri' => '/admin/system/model/:schema/update/:id',
            'method' => 'post',
            'json_data' => json_encode($response->getResults())
        ];

        $this
            ->package('cradlephp/cradle-queue')
            ->queue('webhook-distribution', $webhook);
    } catch (Exception $e) {
    }

    //add a flash
    $this->package('global')->flash(sprintf(
        '%s was Updated',
        $schema->getSingular()
    ), 'success');

    $this->package('global')->redirect($redirect);
});

/**
 * Process the System Model Remove
 *
 * @param Request $request
 * @param Response $response
 */
$this->get('/admin/system/model/:schema/remove/:id', function ($request, $response) {
    //----------------------------//
    // 1. Prepare Data
    //get schema data
    $schema = Schema::i($request->getStage('schema'));

    //table_id, 1 for example
    $request->setStage(
        $schema->getPrimaryFieldName(),
        $request->getStage('id')
    );

    //----------------------------//
    // 2. Process Request
    $this->trigger('system-model-remove', $request, $response);

    //----------------------------//
    // 3. Interpret Results
    if (!$response->isError()) {
        //record logs
        $this->log(
            sprintf(
                '%s #%s removed',
                $schema->getSingular(),
                $request->getStage('id')
            ),
            $request,
            $response
        );
    }

    //redirect
    $redirect = sprintf(
        '/admin/system/model/%s/search',
        $schema->getName()
    );

    //if there is a specified redirect
    if ($request->getStage('redirect_uri')) {
        //set the redirect
        $redirect = $request->getStage('redirect_uri');
    }

    //if we dont want to redirect
    if ($redirect === 'false') {
        return;
    }

    // execute webhook distribution
    try {
        $webhook = [
            'uri' => '/admin/system/model/:schema/remove/:id',
            'method' => 'get',
            'json_data' => json_encode($response->getResults())
        ];

        $this
            ->package('cradlephp/cradle-queue')
            ->queue('webhook-distribution', $webhook);
    } catch (Exception $e) {
    }

    if ($response->isError()) {
        //add a flash
        $this->package('global')->flash($response->getMessage(), 'error');
    } else {
        //add a flash
        $message = $this->package('global')->translate('%s was Removed', $schema->getSingular());
        $this->package('global')->flash($message, 'success');
    }

    $this->package('global')->redirect($redirect);
});

/**
 * Process the System Model Restore
 *
 * @param Request $request
 * @param Response $response
 */
$this->get('/admin/system/model/:schema/restore/:id', function ($request, $response) {
    //----------------------------//
    // 1. Prepare Data
    //get schema data
    $schema = Schema::i($request->getStage('schema'));

    //table_id, 1 for example
    $request->setStage(
        $schema->getPrimaryFieldName(),
        $request->getStage('id')
    );

    //----------------------------//
    // 2. Process Request
    $this->trigger('system-model-restore', $request, $response);

    //----------------------------//
    // 3. Interpret Results
    if (!$response->isError()) {
        //record logs
        $this->log(
            sprintf(
                '%s #%s restored.',
                $schema->getSingular(),
                $request->getStage('id')
            ),
            $request,
            $response
        );
    }

    //redirect
    $redirect = sprintf(
        '/admin/system/model/%s/search',
        $schema->getName()
    );

    //if there is a specified redirect
    if ($request->getStage('redirect_uri')) {
        //set the redirect
        $redirect = $request->getStage('redirect_uri');
    }

    //if we dont want to redirect
    if ($redirect === 'false') {
        return;
    }

    // execute webhook distribution
    try {
        $webhook = [
            'uri' => '/admin/system/model/:schema/restore/:id',
            'method' => 'get',
            'json_data' => json_encode($response->getResults())
        ];

        $this
            ->package('cradlephp/cradle-queue')
            ->queue('webhook-distribution', $webhook);
    } catch (Exception $e) {
    }

    if ($response->isError()) {
        //add a flash
        $this->package('global')->flash($response->getMessage(), 'error');
    } else {
        //add a flash
        $message = $this->package('global')->translate('%s was Restored', $schema->getSingular());
        $this->package('global')->flash($message, 'success');
    }

    $this->package('global')->redirect($redirect);
});

/**
 * Process Object Import
 *
 * @param Request $request
 * @param Response $response
 */
$this->post('/admin/system/model/:schema/import', function ($request, $response) {
    //----------------------------//
    // 1. Prepare Data
    $schema = Schema::i($request->getStage('schema'));

    // data
    $data = [];

    // try to parse the data
    try {
        // decode the data
        $data = @json_decode($request->getBody(), true);
    } catch(\Exception $e) {
        return $response
            ->setContent(json_encode([
                'error' => true,
                'message' => 'Unable to parse data',
                'errors' => [
                    'Unable to parse data',
                    $e->getMessage()
                ]
            ]));
    }

    // set data
    $request->setStage('rows', $data);

    //----------------------------//
    // 2. Process Request
    // catch errors for better debugging
    try {
        $this->trigger('system-model-import', $request, $response);
    } catch(\Exception $e) {
        return $response
            ->setContent(json_encode([
                'error' => true,
                'message' => $e->getMessage(),
                'errors' => [
                    $e->getMessage()
                ]
            ]));
    }

    //----------------------------//
    // 3. Interpret Results
    //if the import event returned errors
    if ($response->isError()) {
        $errors = [];
        //loop through each row
        foreach ($response->getValidation() as $i => $validation) {
            //and loop through each error
            foreach ($validation as $key => $error) {
                //add the error
                $errors[] = sprintf('ROW %s - %s: %s', $i, $key, $error);
            }
        }

        //Set JSON Content
        return $response->setContent(json_encode([
            'error' => true,
            'message' => $response->getMessage(),
            'errors' => $errors
        ]));
    }

    //record logs
    $this->log(
        sprintf(
            '%s was Imported',
            $schema->getPlural()
        ),
        $request,
        $response
    );

    //add a flash
    $message = $this->package('global')->translate(sprintf(
        '%s was Imported',
        $schema->getPlural()
    ), 'success');

    if ($request->getStage('render') != 'false') {
        // execute webhook distribution
        try {
            $webhook = [
                'uri' => '/admin/system/model/:schema/import',
                'method' => 'post',
                'json_data' => json_encode($response->getResults())
            ];

            $this
                ->package('cradlephp/cradle-queue')
                ->queue('webhook-distribution', $webhook);
        } catch (Exception $e) {
    }
    }

    //Set JSON Content
    return $response->setContent(json_encode([
        'error' => false,
        'message' => $message
    ]));
});

/**
 * Process Object Export
 *
 * @param Request $request
 * @param Response $response
 */
$this->get('/admin/system/model/:schema/export/:type', function ($request, $response) {
    //----------------------------//
    // 1. Prepare Data
    //get schema data
    $schema = Schema::i($request->getStage('schema'));

    //if exist get schema
    if ($request->hasStage('relation')) {
        $relation = $request->getStage('relation');
    }

    //filter possible filter options
    //we do this to prevent SQL injections
    if (is_array($request->getStage('filter'))) {
        $filterable = $schema->getFilterableFieldNames();

        //allow relation primary
        if (isset($relation['schema']['primary'])) {
            $filterable[] = $relation['schema']['primary'];
        }

        foreach ($request->getStage('filter') as $key => $value) {
            if (!in_array($key, $filterable)) {
                $request->removeStage('filter', $key);
            }
        }
    }

    //filter possible sort options
    //we do this to prevent SQL injections
    if (is_array($request->getStage('order'))) {
        $sortable = $schema->getSortableFieldNames();

        foreach ($request->getStage('order') as $key => $value) {
            if (!in_array($key, $sortable)) {
                $request->removeStage('order', $key);
            }
        }
    }

    //filter possible filter options
    //we do this to prevent SQL injections
    //check if filter column has empty value
    if (is_array($request->getStage('filter'))) {
        foreach ($request->getStage('filter') as $key => $value) {
            //if invalid key format or there is no value
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $key) || !strlen($value)) {
                $request->removeStage('filter', $key);
            }
        }
    }

    //filter possible sort options
    //we do this to prevent SQL injections
    //check if filter column has empty value
    if (is_array($request->getStage('order'))) {
        foreach ($request->getStage('order') as $key => $value) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                $request->removeStage('order', $key);
            }
        }
    }

    $request->setStage('range', 0);

    //----------------------------//
    // 2. Process Request
    $this->trigger('system-model-search', $request, $response);

    //----------------------------//
    // 3. Interpret Results
    //get the output type
    $type = $request->getStage('type');
    //get the rows
    $rows = $response->getResults('rows');
    //determine the filename
    $filename = $schema->getPlural() . '-' . date('Y-m-d');

    //flatten all json columns
    foreach ($rows as $i => $row) {
        foreach ($row as $key => $value) {
            //transform oobject to array
            if (is_object($value)) {
                $value = (array) $value;
            }

            //if array, let's flatten
            if (is_array($value)) {
                //if no count
                if (!count($value)) {
                    $rows[$i][$key] = '';
                    continue;
                }

                //if regular array
                if (isset($value[0])) {
                    $rows[$i][$key] = implode(',', $value);
                    continue;
                }

                $rows[$i][$key] = json_encode($value);
                continue;
            }

            //provision for any other conversions needed
        }
    }

    //if the output type is csv
    if ($type === 'csv') {
        //if there are no rows
        if (empty($rows)) {
            //at least give the headers
            $rows = [array_keys($schema->getFields())];
        } else {
            //add the headers
            array_unshift($rows, array_keys($rows[0]));
        }

        //set the output headers
        $response
            ->addHeader('Content-Encoding', 'UTF-8')
            ->addHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->addHeader('Content-Disposition', 'attachment; filename=' . $filename . '.csv');

        //open a tmp file
        $file = tmpfile();
        //for each row
        foreach ($rows as $row) {
            //add it to the tmp file as a csv
            fputcsv($file, array_values($row));
        }

        //this is the final output
        $contents = '';

        //rewind the file pointer
        rewind($file);
        //and set all the contents
        while (!feof($file)) {
            $contents .= fread($file, 8192);
        }

        //close the tmp file
        fclose($file);

        //set contents
        return $response->setContent($contents);
    }

    //if the output type is xml
    if ($type === 'xml') {
        //recursive xml parser
        $toXml = function ($array, $xml) use (&$toXml) {
            //for each array
            foreach ($array as $key => $value) {
                //if the value is an array
                if (is_array($value)) {
                    //if the key is not a number
                    if (!is_numeric($key)) {
                        //send it out for further processing (recursive)
                        $toXml($value, $xml->addChild($key));
                        continue;
                    }

                    //send it out for further processing (recursive)
                    $toXml($value, $xml->addChild('item'));
                    continue;
                }

                //add the value
                $xml->addChild($key, htmlspecialchars($value));
            }

            return $xml;
        };

        //set up the xml template
        $root = sprintf(
            "<?xml version=\"1.0\"?>\n<%s></%s>",
            $schema->getName(),
            $schema->getName()
        );

        //set the output headers
        $response
            ->addHeader('Content-Encoding', 'UTF-8')
            ->addHeader('Content-Type', 'text/xml; charset=UTF-8')
            ->addHeader('Content-Disposition', 'attachment; filename=' . $filename . '.xml');

        //get the contents
        $contents = $toXml($rows, new SimpleXMLElement($root))->asXML();

        //set the contents
        return $response->setContent($contents);
    }

    //json maybe?

    //set the output headers
    $response
        ->addHeader('Content-Encoding', 'UTF-8')
        ->addHeader('Content-Type', 'text/json; charset=UTF-8')
        ->addHeader('Content-Disposition', 'attachment; filename=' . $filename . '.json');

    //set content
    $response->set('json', $rows);
});

/**
 * Render the System Model Calendar Page
 *
 * @param Request $request
 * @param Response $response
 */
$this->get('/admin/system/model/:schema/calendar', function ($request, $response) {
    //----------------------------//
    // 1. Prepare Data
    $data = $request->getStage();

    // set redirect
    $redirect = sprintf(
        '/admin/system/model/%s/search',
        $request->getStage('schema')
    );

    if ($request->getStage('redirect_uri')) {
        $redirect = $request->getStage('redirect_uri');
    }

    $request->setStage('redirect_uri', $redirect);

    // if no ajax set, set this page as default
    if (!isset($data['ajax']) || empty($data['ajax'])) {
        $data['ajax'] = sprintf(
            '/admin/system/model/%s/search',
            $request->getStage('schema')
        );
    }

    // if no detail set, set update page as the default
    if (!isset($data['detail']) || empty($data['detail'])) {
        $data['detail'] = sprintf(
            '/admin/system/model/%s/update',
            $request->getStage('schema')
        );
    }

    //----------------------------//
    // 2. Validate
    // does the schema exists?
    try {
        $data['schema'] = Schema::i($request->getStage('schema'))->getAll();
    } catch (\Exception $e) {
        $message = $this
            ->package('global')
            ->translate($e->getMessage());

        $response->setError(true, $message);
    }

    //if this is a return back from processing
    //this form and it's because of an error
    if ($response->isError()) {
        //pass the error messages to the template
        $this
            ->package('global')
            ->flash($response->getMessage(), 'error');
        $this
            ->package('global')
            ->redirect($redirect);
    }

    //also pass the schema to the template
    $dates = ['date', 'datetime', 'created', 'updated', 'time', 'week', 'month'];

    //check what to show
    if (!isset($data['show']) || !$data['show']) {
        //flash an error message and redirect
        $error = $this
            ->package('global')
            ->translate('Please specify what to plot.');
        $this
            ->package('global')
            ->flash($error, 'error');
        $this
            ->package('global')
            ->redirect($redirect);
    }

    $data['show'] = explode(',', $data['show']);
    foreach ($data['show'] as $column) {
        if (isset($data['schema']['fields'][$column])
            && in_array($data['schema']['fields'][$column]['field']['type'], $dates)
        ) {
            continue;
        }

        $error = $this
            ->package('global')
            ->translate('%s is not a date field', $column);
        $this
            ->package('global')
            ->flash($error, 'error');
        $this
            ->package('global')
            ->redirect($redirect);
    }

    //----------------------------//
    // 3. Process
    // set base date & today date for button
    $base = $data['today'] = date('Y-m-d');

    // if there's a start date provide,
    // we have to change our base date
    if (isset($data['start_date']) && $data['start_date']) {
        $base = date('Y-m-d', strtotime($data['start_date']));
    }

    // set default the previous and next date based on our "base" date
    // default is month view
    $prev = strtotime($base . ' -1 month');
    $next = strtotime($base . ' +1 month');

    // set view if not defined
    if (!isset($data['view']) || empty($data['view'])) {
        $data['view'] = 'month';
    }

    // change previous and next date if the user wanted a week view
    if ($data['view'] == 'listWeek' || $data['view'] == 'agendaWeek') {
        $prev = strtotime($base . ' -1 week');
        $next = strtotime($base . ' +1 week');
    }

    // change previous and next date if the user wanted a day view
    if ($data['view'] == 'agendaDay') {
        $prev = strtotime($base .' -1 day');
        $next = strtotime($base .' +1 day');
    }

    // set whatever previous and next date we got from the changes above
    $data['prev'] = date('Y-m-d', $prev);
    $data['next'] = date('Y-m-d', $next);

    // if no breadcrumb set, set a default breadcrumb
    // we will not check if breadcrumb is in array format
    // or has value to be flexible just in case the user
    // doesn't want a breadcrumb
    if (!isset($data['breadcrumb'])) {
        $data['breadcrumb'] = [
            [
                'icon' => 'fas fa-home',
                'link' => '/admin',
                'page' => 'Admin'
            ],
            [
                'icon' => $data['schema']['icon'],
                'link' => $data['schema']['redirect_uri'],
                'page' => $data['schema']['plural']
            ],
            [
                'icon' => 'fas fa-calendar-alt',
                'page' => 'Calendar',
                'active' => true
            ]
        ];
    }

    //----------------------------//
    // 4. Render Template
    $data['title'] = $this
        ->package('global')
        ->translate('%s Calendar', $data['schema']['plural']);

    $class = sprintf(
            'page-admin-%s-calendar page-admin-calendar page-admin',
            $data['schema']['name']
        );

    $body = $this
        ->package('cradlephp/cradle-system')
        ->template('model', 'calendar', $data);

    // set content
    $response
        ->setPage('title', $data['title'])
        ->setPage('class', $class)
        ->setContent($body);

    // if we only want the body
    if ($request->getStage('render') === 'body') {
        return;
    }

    //Render blank page
    $this->trigger('admin-render-page', $request, $response);
});

// /**
//  * Render the System Model Pipeline Page
//  *
//  * @param Request $request
//  * @param Response $response
//  */
// $this->get('/admin/system/model/:schema/pipeline', function ($request, $response) {
//     //----------------------------//
//     // 1. Prepare Data
//     $data = $request->getStage();
//
//     // set redirect
//     $redirect = sprintf(
//         '/admin/system/model/%s/search',
//         $request->getStage('schema')
//     );
//
//     if ($request->getStage('redirect_uri')) {
//         $redirect = $request->getStage('redirect_uri');
//     }
//
//     $request->setStage('redirect_uri', $redirect);
//
//     if (!isset($data['ajax'])) {
//         $data['ajax'] = [];
//     }
//
//     // if no ajax set, set this page as default
//     if (!isset($data['ajax']['pull']) || empty($data['ajax']['pull'])) {
//         $data['ajax']['pull'] = sprintf(
//             '/admin/system/model/%s/search',
//             $request->getStage('schema')
//         );
//     }
//
//     if (!isset($data['ajax']['update']) || empty($data['ajax']['update'])) {
//         $data['ajax']['update'] = sprintf(
//             '/admin/system/model/%s/pipeline',
//             $request->getStage('schema')
//         );
//     }
//
//     // if no detail set, set update page as the default
//     if (!isset($data['detail']) || empty($data['detail'])) {
//         $data['detail'] = sprintf(
//             '/admin/system/model/%s/update',
//             $request->getStage('schema')
//         );
//     }
//
//     //----------------------------//
//     // 2. Validate
//     // does the schema exists?
//     try {
//         $data['schema'] = Schema::i($request->getStage('schema'))->getAll();
//     } catch (\Exception $e) {
//         $message = $this
//             ->package('global')
//             ->translate($e->getMessage());
//
//         $response->setError(true, $message);
//     }
//
//     //if this is a return back from processing
//     //this form and it's because of an error
//     if ($response->isError()) {
//         //pass the error messages to the template
//         $this
//             ->package('global')
//             ->flash($response->getMessage(), 'error');
//         $this
//             ->package('global')
//             ->redirect($redirect);
//     }
//
//     //check what to show
//     if (!isset($data['show']) || !$data['show']) {
//         //flash an error message and redirect
//         $error = $this
//             ->package('global')
//             ->translate('Please specify what to plot.');
//         $this
//             ->package('global')
//             ->flash($error, 'error');
//         $this
//             ->package('global')
//             ->redirect($redirect);
//     }
//
//     // minimize long array chain
//     $fields = $data['schema']['fields'];
//     // pipeline stages
//     $data['stages'] = $fields[$data['show']]['field']['options'];
//
//     $allowed = ['select', 'radios'];
//
//     if (!isset($fields[$data['show']])
//         || !in_array($fields[$data['show']]['field']['type'], $allowed)
//     ) {
//         $error = $this
//             ->package('global')
//             ->translate('%s is not a select/radio field', $show);
//         $this
//             ->package('global')
//             ->flash($error, 'error');
//         $this
//             ->package('global')
//             ->redirect($redirect);
//     }
//
//     $dates = ['date', 'datetime', 'created', 'updated', 'time', 'week', 'month'];
//     if (isset($data['date'])
//         && (!isset($fields[$data['date']])
//             || !in_array($fields[$data['date']]['field']['type'], $dates))
//     ) {
//         $error = $this
//             ->package('global')
//             ->translate('%s is not a type of date field', $data['date']);
//         $this
//             ->package('global')
//             ->flash($error, 'error');
//         $this
//             ->package('global')
//             ->redirect($redirect);
//     }
//
//     // initialize the stageHeader keys into null
//     // so that even there's no total or range, it will not get errors
//     $data['stageHeader'] = [
//         'total' => null,
//         'minRange' => null,
//         'maxRange' => null
//     ];
//
//     // check if the user want's to display the total
//     if (isset($data['total'])) {
//         if (strpos($data['total'], ',') !== false) {
//             $data['total'] = explode(',', $data['total']);
//         }
//
//         // if not array, make it an array
//         if (!is_array($data['total'])) {
//             $data['total'] = [$data['total']];
//         }
//
//         $rangeFieldTypes = ['number', 'small', 'float', 'price'];
//
//         $fields = $data['schema']['fields'];
//         foreach ($data['total'] as $field) {
//             if (isset($fields[$field])
//                 && in_array($fields[$field]['field']['type'], $rangeFieldTypes)
//             ) {
//                 continue;
//             }
//
//             // flash error message
//             $error = $this
//                 ->package('global')
//                 ->translate('%s is not a number type field', $value);
//
//             $this
//                 ->package('global')
//                 ->flash($error, 'error');
//
//             $this
//                 ->package('global')
//                 ->redirect($redirect);
//         }
//     }
//
//     $data['schema']['filterable'] = array_values($data['schema']['filterable']);
//     //----------------------------//
//     // 3. Render Template
//     $data['title'] = $this
//         ->package('global')
//         ->translate('%s Pipeline', $data['schema']['singular']);
//
//     $class = sprintf('page-admin-%s-pipeline page-admin', $request->getStage('schema'));
//     $body = $this
//         ->package('cradlephp/cradle-system')
//         ->template('model', 'board', $data, ['search_form', 'search_filters']);
//
//     //Set Content
//     $response
//         ->setPage('title', $data['title'])
//         ->setPage('class', $class)
//         ->setContent($body);
//
//     //Render blank page
//     $this->trigger('admin-render-page', $request, $response);
// });
//
// /**
//  * Process Pipeline Update
//  *
//  * @param Request $request
//  * @param Response $response
//  */
// $this->post('/admin/system/model/:schema/pipeline', function ($request, $response) {
//     //----------------------------//
//     // get json response data only
//     $request->setStage('redirect_uri', 'false');
//     $request->setStage('render', 'false');
//
//     $data = [];
//     if ($request->getStage()) {
//         $data = $request->getStage();
//     }
//
//     $filters = [];
//
//     // if it reached here, then we're assumming
//     // that the user attempted to do an order/sort update
//     if (isset($data['moved']) && isset($data['sort'])) {
//         if (isset($data['stage']) && isset($data[$data['stage']])) {
//             $filters[] = sprintf(
//                 '%s = "%s"',
//                 $data['stage'],
//                 $data[$data['stage']]
//             );
//         }
//
//         // if it was moved upwards
//         // then we only update the rows from this item to the previous elder
//         if ($request->getStage('moved') == 'upwards') {
//             $request->setStage(
//                 'fields',
//                 [$data['sort'] => $data['sort'] . '+1']
//             );
//
//             $filters = [
//                 $data['sort'] . ' > ' . $data['new_elder'],
//                 $data['sort'] . ' <= ' . $data['previous_elder']
//             ];
//         }
//
//         // if it was moved downwards
//         // then we update from the previous elder to the newest elder
//         if ($request->getStage('moved') == 'downwards') {
//             $request->setStage(
//                 'fields',
//                 [$data['sort'] => $data['sort'] . '-1']
//             );
//             $filters = [
//                 $data['sort'] . ' >= ' . $data['previous_elder'],
//                 $data['sort'] . ' <= ' . $data['new_elder']
//             ];
//
//         }
//
//         $request->setStage('filters', $filters);
//     }
//
//     return $this->routeTo(
//         'post',
//         sprintf(
//             '/admin/system/model/%s/update/%s',
//             $request->getStage('schema'),
//             $request->getStage('id')
//         ),
//         $request,
//         $response
//     );
// });

//Front End Controllers

/**
 * Render the System Model Search Page
 *
 * @param Request $request
 * @param Response $response
 */
$this->get('/system/model/:schema/search', function ($request, $response) {
    //----------------------------//
    // get json data only
    $request->setStage('render', 'false');

    //now let the original search take over
    $this->routeTo(
        'get',
        sprintf(
            '/admin/system/model/%s/search',
            $request->getStage('schema')
        ),
        $request,
        $response
    );

    // if successful, execute webhook distribution
    if (!$response->isError()) {
        try {
            $webhook = [
                'uri' => '/system/model/:schema/search',
                'method' => 'get',
                'json_data' => json_encode($response->getResults())
            ];

            $this
                ->package('cradlephp/cradle-queue')
                ->queue('webhook-distribution', $webhook);
        } catch (Exception $e) {
        }
    }
});

/**
 * Render the System Model Create Page
 *
 * @param Request $request
 * @param Response $response
 */
$this->get('/system/model/:schema/create', function ($request, $response) {
    //----------------------------//
    // get json data only
    $request->setStage('render', 'false');

    //now let the original create take over
    $this->routeTo(
        'get',
        sprintf(
            '/admin/system/model/%s/create',
            $request->getStage('schema')
        ),
        $request,
        $response
    );

    // if successful, execute webhook distribution
    if (!$response->isError()) {
        try {
            $webhook = [
                'uri' => '/system/model/:schema/create',
                'method' => 'get',
                'json_data' => json_encode($response->getResults())
            ];

            $this
                ->package('cradlephp/cradle-queue')
                ->queue('webhook-distribution', $webhook);
        } catch (Exception $e) {
        }
    }
});

/**
 * Render the System Model Update Page
 *
 * @param Request $request
 * @param Response $response
 */
$this->get('/system/model/:schema/update/:id', function ($request, $response) {
    //----------------------------//
    // get json response data only
    $request->setStage('render', 'false');

    //now let the original update take over
    $this->routeTo(
        'get',
        sprintf(
            '/admin/system/model/%s/update/%s',
            $request->getStage('schema'),
            $request->getStage('id')
        ),
        $request,
        $response
    );

    // if successful, execute webhook distribution
    if (!$response->isError()) {
        try {
            $webhook = [
                'uri' => '/system/model/:schema/update/:id',
                'method' => 'get',
                'json_data' => json_encode($response->getResults())
            ];

            $this
                ->package('cradlephp/cradle-queue')
                ->queue('webhook-distribution', $webhook);
        } catch (Exception $e) {
        }
    }
});

/**
 * Process the System Model Search Actions
 *
 * @param Request $request
 * @param Response $response
 */
$this->post('/system/model/:schema/search', function ($request, $response) {
    //----------------------------//
    // get json response data only
    $request->setStage('redirect_uri', 'false');

    //now let the original post search take over
    $this->routeTo(
        'post',
        sprintf(
            '/admin/system/model/%s/search',
            $request->getStage('schema')
        ),
        $request,
        $response
    );
});

/**
 * Process the System Model Create Page
 *
 * @param Request $request
 * @param Response $response
 */
$this->post('/system/model/:schema/create', function ($request, $response) {
    //----------------------------//
    // get json response data only
    $request->setStage('redirect_uri', 'false');

    //now let the original post create take over
    $this->routeTo(
        'post',
        sprintf(
            '/admin/system/model/%s/create',
            $request->getStage('schema')
        ),
        $request,
        $response
    );

    // if successful, execute webhook distribution
    if (!$response->isError()) {
        try {
            $webhook = [
                'uri' => '/system/model/:schema/create',
                'method' => 'post',
                'json_data' => json_encode($response->getResults())
            ];

            $this
                ->package('cradlephp/cradle-queue')
                ->queue('webhook-distribution', $webhook);
        } catch (Exception $e) {
        }
    }
});

/**
 * Process the System Model Update Page
 *
 * @param Request $request
 * @param Response $response
 */
$this->post('/system/model/:schema/update/:id', function ($request, $response) {
    //----------------------------//
    // get json response data only
    $request->setStage('redirect_uri', 'false');

    //now let the original post update take over
    $this->routeTo(
        'post',
        sprintf(
            '/admin/system/model/%s/update/%s',
            $request->getStage('schema'),
            $request->getStage('id')
        ),
        $request,
        $response
    );

    // if successful, execute webhook distribution
    if (!$response->isError()) {
        try {
            $webhook = [
                'uri' => '/system/model/:schema/update/:id',
                'method' => 'post',
                'json_data' => json_encode($response->getResults())
            ];

            $this
                ->package('cradlephp/cradle-queue')
                ->queue('webhook-distribution', $webhook);
        } catch (Exception $e) {
        }
    }
});

/**
 * Process the System Model Remove
 *
 * @param Request $request
 * @param Response $response
 */
$this->get('/system/model/:schema/remove/:id', function ($request, $response) {
    //----------------------------//
    // get json response data only
    $request->setStage('redirect_uri', 'false');

    //now let the original remove take over
    $this->routeTo(
        'get',
        sprintf(
            '/admin/system/model/%s/remove/%s',
            $request->getStage('schema'),
            $request->getStage('id')
        ),
        $request,
        $response
    );

    // if successful, execute webhook distribution
    if (!$response->isError()) {
        try {
            $webhook = [
                'uri' => '/system/model/:schema/remove/:id',
                'method' => 'get',
                'json_data' => json_encode($response->getResults())
            ];

            $this
                ->package('cradlephp/cradle-queue')
                ->queue('webhook-distribution', $webhook);
        } catch (Exception $e) {
        }
    }
});

/**
 * Process the System Model Restore
 *
 * @param Request $request
 * @param Response $response
 */
$this->get('/system/model/:schema/restore/:id', function ($request, $response) {
    //----------------------------//
    // get json response data only
    $request->setStage('redirect_uri', 'false');

    //now let the original restore take over
    $this->routeTo(
        'get',
        sprintf(
            '/admin/system/model/%s/restore/%s',
            $request->getStage('schema'),
            $request->getStage('id')
        ),
        $request,
        $response
    );

    // if successful, execute webhook distribution
    if (!$response->isError()) {
        try {
            $webhook = [
                'uri' => '/system/model/:schema/restore/:id',
                'method' => 'get',
                'json_data' => json_encode($response->getResults())
            ];

            $this
                ->package('cradlephp/cradle-queue')
                ->queue('webhook-distribution', $webhook);
        } catch (Exception $e) {
        }
    }
});

/**
 * Process Object Import
 *
 * @param Request $request
 * @param Response $response
 */
$this->post('/system/model/:schema/import', function ($request, $response) {
    $request->setStage('render', 'false');
    //----------------------------//
    //trigger original import route
    $this->routeTo(
        'post',
        sprintf(
            '/admin/system/model/%s/import',
            $request->getStage('schema')
        ),
        $request,
        $response
    );

    // execute webhook distribution
    try {
        $webhook = [
            'uri' => '/system/model/:schema/import',
            'method' => 'post',
            'json_data' => json_encode($response->getResults())
        ];

        $this
            ->package('cradlephp/cradle-queue')
            ->queue('webhook-distribution', $webhook);
    } catch (Exception $e) {

    }
});

/**
 * Process Object Export
 *
 * @param Request $request
 * @param Response $response
 */
$this->get('/system/model/:schema/export/:type', function ($request, $response) {
    //----------------------------//
    //trigger original export route
    $this->routeTo(
        'get',
        sprintf(
            '/admin/system/model/%s/export/%s',
            $request->getStage('schema'),
            $request->getStage('type')
        ),
        $request,
        $response
    );
});
