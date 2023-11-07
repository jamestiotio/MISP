<?php

namespace App\Controller;

use App\Controller\AppController;
use App\Lib\Tools\ClusterRelationsGraphTool;
use App\Lib\Tools\FileAccessTool;
use App\Lib\Tools\JsonTool;
use Cake\Core\Configure;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\MethodNotAllowedException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Validation\Validation;
use Exception;

class GalaxiesController extends AppController
{
    use LocatorAwareTrait;

    public $paginate = [
        'limit' => 60,
        'recursive' => 0,
        'order' => [
            'Galaxies.id' => 'DESC'
        ]
    ];

    public function index()
    {
        $filterData = [
            'request' => $this->request,
            'named_params' => $this->request->getParam('named'),
            'paramArray' => ['value', 'enabled'],
            'ordered_url_params' => [],
            'additional_delimiters' => PHP_EOL
        ];
        $exception = false;
        $filters = $this->harvestParameters($filterData, $exception);
        $searchConditions = [];
        if (empty($filters['value'])) {
            $filters['value'] = '';
        } else {
            $searchall = '%' . strtolower($filters['value']) . '%';
            $searchConditions = [
                'OR' => [
                    'LOWER(Galaxies.name) LIKE' => $searchall,
                    'LOWER(Galaxies.namespace) LIKE' => $searchall,
                    'LOWER(Galaxies.description) LIKE' => $searchall,
                    'LOWER(Galaxies.kill_chain_order) LIKE' => $searchall,
                    'Galaxies.uuid LIKE' => $searchall
                ]
            ];
        }
        if (isset($filters['enabled'])) {
            $searchConditions[]['enabled'] = $filters['enabled'] ? 1 : 0;
        }
        if ($this->ParamHandler->isRest()) {
            $galaxies = $this->Galaxies->find(
                'all',
                [
                    'recursive' => -1,
                    'conditions' => [
                        'AND' => $searchConditions
                    ]
                ]
            )->disableHydration()->toArray();
            return $this->RestResponse->viewData($galaxies, $this->response->getType());
        } else {
            $this->paginate['conditions']['AND'][] = $searchConditions;
            $galaxies = $this->paginate();
            $this->set('galaxyList', $galaxies);
            $this->set('passedArgsArray', $this->passedArgs);
            $this->set('searchall', $filters['value']);
        }
    }

    public function update()
    {
        if (!$this->request->is('post')) {
            throw new MethodNotAllowedException('This action is only accessible via POST requests.');
        }
        if (!empty($this->request->getParam('named')['force'])) {
            $force = 1;
        } else {
            $force = 0;
        }
        $result = $this->Galaxies->update($force);
        $message = __('Galaxies updated.');
        if ($this->ParamHandler->isRest()) {
            return $this->RestResponse->saveSuccessResponse('Galaxy', 'update', false, $this->response->getType(), $message);
        } else {
            $this->Flash->success($message);
            $this->redirect(['controller' => 'galaxies', 'action' => 'index']);
        }
    }

    public function wipe_default()
    {
        if (!$this->request->is('post')) {
            throw new MethodNotAllowedException('This action is only accessible via POST requests.');
        }
        $result = $this->Galaxy->GalaxyCluster->wipe_default();
        $message = __('Default galaxy clusters dropped.');
        if ($this->ParamHandler->isRest()) {
            return $this->RestResponse->saveSuccessResponse('Galaxy', 'wipe_default', false, $this->response->getType(), $message);
        } else {
            $this->Flash->success($message);
            $this->redirect(['controller' => 'galaxies', 'action' => 'index']);
        }
    }

    public function view($id)
    {
        $id = $this->Toolbox->findIdByUuid($this->Galaxy, $id);
        $passedArgsArray = [
            'context' => isset($this->params['named']['context']) ? $this->params['named']['context'] : 'all'
        ];
        if (isset($this->params['named']['searchall']) && strlen($this->params['named']['searchall']) > 0) {
            $passedArgsArray['searchall'] = $this->params['named']['searchall'];
        }
        $this->set('passedArgsArray', $passedArgsArray);
        if ($this->ParamHandler->isRest()) {
            $galaxy = $this->Galaxy->find(
                'first',
                [
                    'contain' => ['GalaxyCluster' => ['GalaxyElement'/*, 'GalaxyReference'*/]],
                    'recursive' => -1,
                    'conditions' => ['Galaxy.id' => $id]
                ]
            );
            if (empty($galaxy)) {
                throw new NotFoundException('Galaxy not found.');
            }
            return $this->RestResponse->viewData($galaxy, $this->response->getType());
        } else {
            $galaxy = $this->Galaxy->find(
                'first',
                [
                    'recursive' => -1,
                    'conditions' => ['Galaxy.id' => $id]
                ]
            );
            if (empty($galaxy)) {
                throw new NotFoundException('Galaxy not found.');
            }
            $this->set('galaxy', $galaxy);
        }
    }

    public function delete($id)
    {
        if (Validation::uuid($id)) {
            $id = $this->Toolbox->findIdByUuid($this->Galaxy, $id);
        } elseif (!is_numeric($id)) {
            throw new NotFoundException('Invalid galaxy.');
        }

        $galaxy = $this->Galaxy->find(
            'first',
            [
                'recursive' => -1,
                'conditions' => ['Galaxy.id' => $id]
            ]
        );
        if (empty($galaxy)) {
            throw new NotFoundException('Invalid galaxy.');
        }
        $result = $this->Galaxy->delete($id);
        if ($result) {
            $message = __('Galaxy deleted');
            if ($this->ParamHandler->isRest()) {
                return $this->RestResponse->saveSuccessResponse('Galaxy', 'delete', false, $this->response->getType(), $message);
            } else {
                $this->Flash->success($message);
                $this->redirect(['controller' => 'galaxies', 'action' => 'index']);
            }
        } else {
            $message = __('Could not delete Galaxy.');
            if ($this->ParamHandler->isRest()) {
                return $this->RestResponse->saveFailResponse('Galaxy', 'delete', false, $message);
            } else {
                $this->Flash->success($message);
                $this->redirect($this->referer());
            }
        }
    }

    public function enable($id)
    {
        return $this->toggle($id, true);
    }

    public function disable($id)
    {
        return $this->toggle($id, false);
    }

    public function toggle($id, $enabled = null)
    {
        if (Validation::uuid($id)) {
            $id = $this->Toolbox->findIdByUuid($this->Galaxy, $id);
        } elseif (!is_numeric($id)) {
            throw new NotFoundException('Invalid galaxy.');
        }

        $galaxy = $this->Galaxy->find(
            'first',
            [
                'recursive' => -1,
                'conditions' => ['Galaxy.id' => $id]
            ]
        );
        if (empty($galaxy)) {
            throw new NotFoundException('Invalid galaxy.');
        }
        if (is_null($enabled)) {
            $galaxy['Galaxy']['enabled'] = !$galaxy['Galaxy']['enabled'];
        } else {
            $galaxy['Galaxy']['enabled'] = $enabled;
        }
        $result = $this->Galaxy->save($galaxy);
        if ($result) {
            $message = __('Galaxy enabled');
            if ($this->ParamHandler->isRest()) {
                return $this->RestResponse->saveSuccessResponse('Galaxy', 'toggle', false, $this->response->getType(), $message);
            } else {
                $this->Flash->success($message);
                $this->redirect(['controller' => 'galaxies', 'action' => 'index']);
            }
        } else {
            $message = __('Could not enable Galaxy.');
            if ($this->ParamHandler->isRest()) {
                return $this->RestResponse->saveFailResponse('Galaxy', 'toggle', false, $message);
            } else {
                $this->Flash->success($message);
                $this->redirect($this->referer());
            }
        }
    }

    public function import()
    {
        if ($this->request->is('post') || $this->request->is('put')) {
            if ($this->ParamHandler->isRest()) {
                $clusters = $this->request->getData();
            } else {
                $data = $this->request->getData()['Galaxy'] ?? $this->request->getData();
                $text = FileAccessTool::getTempUploadedFile($data['submittedjson'], $data['json']);
                try {
                    $clusters = JsonTool::decodeArray($text);
                } catch (Exception $e) {
                    throw new BadRequestException(__('Error while decoding JSON'));
                }
            }
            $saveResult = $this->Galaxy->importGalaxyAndClusters($this->Auth->user(), $clusters);
            if ($saveResult['success']) {
                $message = __('Galaxy clusters imported. %s imported, %s ignored, %s failed. %s', $saveResult['imported'], $saveResult['ignored'], $saveResult['failed'], !empty($saveResult['errors']) ? implode(', ', $saveResult['errors']) : '');
                if ($this->ParamHandler->isRest()) {
                    return $this->RestResponse->saveSuccessResponse('Galaxy', 'import', false, $this->response->getType(), $message);
                } else {
                    $this->Flash->success($message);
                    $this->redirect(['controller' => 'galaxies', 'action' => 'index']);
                }
            } else {
                $message = __('Could not import galaxy clusters. %s imported, %s ignored, %s failed. %s', $saveResult['imported'], $saveResult['ignored'], $saveResult['failed'], !empty($saveResult['errors']) ? implode(', ', $saveResult['errors']) : '');
                if ($this->ParamHandler->isRest()) {
                    return $this->RestResponse->saveFailResponse('Galaxy', 'import', false, $message);
                } else {
                    $this->Flash->error($message);
                }
            }
        }
        $this->set('action', 'import');
    }

    // Ingests clusters coming from a sync request
    public function pushCluster()
    {
        if (!$this->Auth->user()['Role']['perm_sync'] || !$this->Auth->user()['Role']['perm_galaxy_editor']) {
            throw new MethodNotAllowedException(__('You do not have the permission to do that.'));
        }
        if (!$this->ParamHandler->isRest()) {
            throw new MethodNotAllowedException(__('This action is only accessible via a REST request.'));
        }
        if ($this->request->is('post')) {
            $clusters = $this->request->getData();
            $saveResult = $this->Galaxy->importGalaxyAndClusters($this->Auth->user(), $clusters);
            $messageInfo = __('%s imported, %s ignored, %s failed. %s', $saveResult['imported'], $saveResult['ignored'], $saveResult['failed'], !empty($saveResult['errors']) ? implode(', ', $saveResult['errors']) : '');
            if ($saveResult['success']) {
                $message = __('Galaxy clusters imported. ') . $messageInfo;
                return $this->RestResponse->saveSuccessResponse('Galaxy', 'pushCluster', false, $this->response->getType(), $message);
            } else {
                $message = __('Could not import galaxy clusters. ') . $messageInfo;
                return $this->RestResponse->saveFailResponse('Galaxy', 'pushCluster', false, $message);
            }
        }
    }

    public function export($galaxyId)
    {
        $galaxy = $this->Galaxy->find(
            'first',
            [
                'recursive' => -1,
                'conditions' => ['Galaxy.id' => $galaxyId]
            ]
        );
        if (empty($galaxy) && $galaxyId !== null) {
            throw new NotFoundException('Galaxy not found.');
        }
        if ($this->request->is('post') || $this->request->is('put')) {
            $requestData = $this->request->getData()['Galaxy'] ? $this->request->getData()['Galaxy'] : $this->request->getData();
            $clusterType = [];
            if ($requestData['default']) {
                $clusterType[] = true;
            }
            if ($requestData['custom']) {
                $clusterType[] = false;
            }
            $options = [
                'conditions' => [
                    'GalaxyCluster.galaxy_id' => $galaxyId,
                    'GalaxyCluster.distribution' => $requestData['distribution'],
                    'GalaxyCluster.default' => $clusterType
                ]
            ];
            $clusters = $this->Galaxy->GalaxyCluster->fetchGalaxyClusters($this->Auth->user(), $options, $full = true);
            $clusters = $this->Galaxy->GalaxyCluster->unsetFieldsForExport($clusters);
            if ($requestData['format'] == 'misp-galaxy') {
                $clusters = $this->Galaxy->convertToMISPGalaxyFormat($galaxy, $clusters);
            }
            $content = json_encode($clusters, JSON_PRETTY_PRINT);
            $this->response = $this->response->withType('json');
            $this->response = $this->response->withStringBody($content);
            if ($requestData['download'] == 'download') {
                $this->response = $this->response->withDownload(sprintf('galaxy_%s_%s.json', $galaxy['Galaxy']['uuid'], time()));
            }
            return $this->response;
        } else {
            $this->set('galaxy', $galaxy);
            $AttributeTable = $this->fetchTable('Attributes');
            $distributionLevels = $AttributeTable->distributionLevels;
            unset($distributionLevels[5]);
            $distributionLevels[4] = __('All sharing groups');
            $this->set('distributionLevels', $distributionLevels);
            $this->set('action', 'export');
        }
    }

    public function selectGalaxy($target_id, $target_type = 'event', $namespace = 'misp', $noGalaxyMatrix = false)
    {
        $this->closeSession();
        $mitreAttackGalaxyId = $this->Galaxy->getMitreAttackGalaxyId();
        $local = !empty($this->params['named']['local']) ? $this->params['named']['local'] : '0';
        $eventid = !empty($this->params['named']['eventid']) ? $this->params['named']['eventid'] : '0';

        $conditions = ['enabled' => true];
        if ($namespace !== '0') {
            $conditions['namespace'] = $namespace;
        }
        if (!$local) {
            $conditions['local_only'] = false;
        }
        $galaxies = $this->Galaxy->find(
            'all',
            [
                'recursive' => -1,
                'fields' => ['MAX(Galaxy.version) as latest_version', 'id', 'kill_chain_order', 'name', 'icon', 'description'],
                'conditions' => $conditions,
                'group' => ['name', 'id', 'kill_chain_order', 'icon', 'description'],
                'order' => ['name asc']
            ]
        );
        $items = [
            [
                'name' => __('All clusters'),
                'value' => $this->baseurl . "/galaxies/selectCluster/" . h($target_id) . '/' . h($target_type) . '/0/local:' . h($local) . '/eventid:' . h($eventid)
            ]
        ];
        foreach ($galaxies as $galaxy) {
            if (!isset($galaxy['Galaxy']['kill_chain_order']) || $noGalaxyMatrix) {
                $items[] = [
                    'name' => h($galaxy['Galaxy']['name']),
                    'value' => $this->baseurl . "/galaxies/selectCluster/" . h($target_id) . '/' . h($target_type) . '/' . $galaxy['Galaxy']['id'] . '/local:' . h($local) . '/eventid:' . h($eventid),
                    'template' => [
                        'preIcon' => 'fa-' . $galaxy['Galaxy']['icon'],
                        'name' => $galaxy['Galaxy']['name'],
                        'infoExtra' => $galaxy['Galaxy']['description'],
                    ]
                ];
            } else { // should use matrix instead
                $param = [
                    'name' => $galaxy['Galaxy']['name'],
                    'value' => $this->baseurl . "/galaxies/selectCluster/" . h($target_id) . '/' . h($target_type) . '/' . $galaxy['Galaxy']['id'] . '/local:' . h($local) . '/eventid:' . h($eventid),
                    'functionName' => sprintf(
                        "getMatrixPopup('%s', '%s', '%s/local:%s/eventid:%s')",
                        h($target_type),
                        h($target_id),
                        h($galaxy['Galaxy']['id']),
                        h($local),
                        h($eventid)
                    ),
                    'isPill' => true,
                    'isMatrix' => true
                ];
                if ($galaxy['Galaxy']['id'] == $mitreAttackGalaxyId) {
                    $param['img'] = $this->baseurl . "/img/mitre-attack-icon.ico";
                }
                $items[] = $param;
            }
        }

        $this->set('items', $items);
        $this->render('/Elements/generic_picker');
    }

    public function selectGalaxyNamespace($target_id, $target_type = 'event', $noGalaxyMatrix = false)
    {
        $this->closeSession();
        $namespaces = $this->Galaxy->find(
            'column',
            [
                'recursive' => -1,
                'fields' => ['namespace'],
                'conditions' => ['enabled' => 1],
                'unique' => true,
                'order' => ['namespace asc']
            ]
        );
        $local = !empty($this->params['named']['local']) ? '1' : '0';
        $eventid = !empty($this->params['named']['eventid']) ? $this->params['named']['eventid'] : '0';
        $noGalaxyMatrix = $noGalaxyMatrix ? '1' : '0';
        $items = [[
            'name' => __('All namespaces'),
            'value' => $this->baseurl . "/galaxies/selectGalaxy/" . h($target_id) . '/' . h($target_type) . '/0/' . h($noGalaxyMatrix) . '/local:' . h($local) . '/eventid:' . h($eventid)
        ]
        ];
        foreach ($namespaces as $namespace) {
            $items[] = [
                'name' => $namespace,
                'value' => $this->baseurl . "/galaxies/selectGalaxy/" . h($target_id) . '/' . h($target_type) . '/' . h($namespace) . '/' . h($noGalaxyMatrix) . '/local:' . h($local) . '/eventid:' . h($eventid)
            ];
        }

        $this->set('items', $items);
        $this->set(
            'options',
            [ // set chosen (select picker) options
                'multiple' => 0,
            ]
        );
        $this->render('/Elements/generic_picker');
    }

    public function selectCluster($target_id, $target_type = 'event', $selectGalaxy = false)
    {
        $user = $this->closeSession();
        $conditions = [
            'OR' => [
                'GalaxyCluster.published' => true,
                'GalaxyCluster.default' => true,
            ],
            'AND' => [
                'GalaxyCluster.deleted' => false,
            ]
        ];
        if ($target_type == 'galaxyClusterRelation') {
            $conditions['OR']['GalaxyCluster.published'] = [true, false];
        }
        if ($selectGalaxy) {
            $conditions['GalaxyCluster.galaxy_id'] = $selectGalaxy;
        }
        $data = array_column(
            $this->Galaxy->GalaxyCluster->fetchGalaxyClusters(
                $user,
                [
                    'conditions' => $conditions,
                    'fields' => ['value', 'description', 'source', 'type', 'id', 'uuid'],
                    'order' => ['value asc'],
                ]
            ),
            'GalaxyCluster'
        );
        $synonyms = $this->Galaxy->GalaxyCluster->GalaxyElement->find(
            'all',
            [
                'conditions' => [
                    'GalaxyElement.key' => 'synonyms',
                    $conditions
                ],
                'fields' => ['GalaxyElement.galaxy_cluster_id', 'GalaxyElement.value'],
                'contain' => 'GalaxyCluster',
                'recursive' => -1
            ]
        );
        $sortedSynonyms = [];
        foreach ($synonyms as $synonym) {
            $sortedSynonyms[$synonym['GalaxyElement']['galaxy_cluster_id']][] = $synonym['GalaxyElement']['value'];
        }
        $clusters = [];
        foreach ($data as $cluster) {
            if (!empty($sortedSynonyms[$cluster['id']])) {
                $cluster['synonyms_string'] = implode(', ', $sortedSynonyms[$cluster['id']]);
            }
            $clusters[$cluster['type']][$cluster['uuid']] = $cluster;
        }
        ksort($clusters);

        $items = [];
        foreach ($clusters as $cluster_data) {
            foreach ($cluster_data as $cluster) {
                $optionName = $cluster['value'];
                if (isset($cluster['synonyms_string'])) {
                    $optionName .= ' (' . $cluster['synonyms_string'] . ')';
                }
                $itemParam = [
                    'name' => $optionName,
                    'value' => $cluster['id'],
                    'template' => [
                        'name' => $cluster['value'],
                        'infoExtra' => $cluster['description'],
                    ],
                    'additionalData' => [
                        'uuid' => $cluster['uuid']
                    ]
                ];
                if (isset($cluster['synonyms_string'])) {
                    $itemParam['template']['infoContextual'] =  __('Synonyms: ') . $cluster['synonyms_string'];
                }
                $items[] = $itemParam;
            }
        }
        if ($this->ParamHandler->isRest()) {
            return $this->RestResponse->viewData($items, $this->response->getType());
        }
        $mirrorOnEventEnabled = Configure::read("MISP.enable_clusters_mirroring_from_attributes_to_event");
        $mirrorOnEvent = $mirrorOnEventEnabled && $target_type == 'attribute';
        $this->set('target_id', $target_id);
        $this->set('target_type', $target_type);
        $this->set('mirrorOnEvent', $mirrorOnEvent);
        $this->set('items', $items);
        $local = !empty($this->params['named']['local']) ? $this->params['named']['local'] : '0';
        $this->set(
            'options',
            [ // set chosen (select picker) options
                'functionName' => 'quickSubmitGalaxyForm',
                'multiple' => $target_type == 'galaxyClusterRelation' ? 0 : '-1',
                'select_options' => [
                    'additionalData' => [
                        'target_id' => $target_id,
                        'target_type' => $target_type,
                        'local' => $local
                    ]
                ],
            ]
        );
        $this->render('ajax/cluster_choice');
    }

    public function attachCluster($target_id, $target_type = 'event')
    {
        $local = !empty($this->request->getParam('local'));
        $cluster_id = $this->request->getData('target_id');
        $user = $this->Auth->user();

        $target = $this->Galaxy->fetchTarget($user, $target_type, $target_id);
        if (empty($target)) {
            throw new NotFoundException(__('Invalid %s.', $target_type));
        }
        if ($target_type === 'event' || $target_type === 'attribute') {
            if (!$this->ACL->canModifyTag($user, $target, $local)) {
                throw new ForbiddenException(__('No permission to attach this cluster to given target.'));
            }
        } else {
            if (!$this->ACL->canModifyTagCollection($user, $target)) {
                throw new ForbiddenException(__('No permission to attach this cluster to given target.'));
            }
        }

        $result = $this->Galaxy->attachCluster($user, $target_type, $target, $cluster_id, $local);
        return new Response(['body' => json_encode(['saved' => true, 'success' => $result, 'check_publish' => true]), 'status' => 200, 'type' => 'json']);
    }

    public function attachMultipleClusters($target_id, $target_type = 'event')
    {
        $local = !empty($this->request->getParam('local'));
        $mirrorOnEventEnabled = Configure::read("MISP.enable_clusters_mirroring_from_attributes_to_event");
        $mirrorOnEvent = $mirrorOnEventEnabled && $target_type === 'attribute';

        if ($this->request->is('post')) {
            $user = $this->Auth->user();
            if ($target_id === 'selected') {
                $target_id_list = $this->_jsonDecode($this->request->getData()['Galaxy']['attribute_ids']);
            } else {
                $target_id_list = [$target_id];
            }
            $cluster_ids = $this->request->getData()['Galaxy']['target_ids'];
            $mirrorOnEventRequested = $mirrorOnEvent && !empty($this->request->getData()['Galaxy']['mirror_on_event']);
            if (strlen($cluster_ids) > 0) {
                $cluster_ids = $this->_jsonDecode($cluster_ids);
                if (empty($cluster_ids)) {
                    return new Response(['body' => json_encode(['saved' => false, 'errors' => __('No clusters picked.')]), 'status' => 200, 'type' => 'json']);
                }
            } else {
                return new Response(['body' => json_encode(['saved' => false, 'errors' => __('Failed to parse request.')]), 'status' => 200, 'type' => 'json']);
            }
            if ($mirrorOnEventRequested && !empty($target_id_list)) {
                $first_attribute_id = $target_id_list[0]; // We consider that all attributes to be tagged are contained in the same event.

                $AttributeTable = $this->fetchTable('Attributes');
                $attribute = $AttributeTable->fetchAttributeSimple($user, ['conditions' => ['Attribute.id' => $first_attribute_id]]);
                if (!empty($attribute['Attribute']['event_id'])) {
                    $event_id = $attribute['Attribute']['event_id'];
                } else {
                    return new Response(['body' => json_encode(['saved' => false, 'errors' => __('Failed to parse request. Could not fetch attribute')]), 'status' => 200, 'type' => 'json']);
                }
            }
            $result = "";
            if (!is_array($cluster_ids)) { // in case we only want to attach 1
                $cluster_ids = [$cluster_ids];
            }
            foreach ($cluster_ids as $cluster_id) {
                foreach ($target_id_list as $target_id) {
                    $target = $this->Galaxy->fetchTarget($user, $target_type, $target_id);
                    if (empty($target)) {
                        throw new NotFoundException(__('Invalid %s.', $target_type));
                    }
                    if ($target_type === 'event' || $target_type === 'attribute') {
                        if (!$this->ACL->canModifyTag($user, $target, $local)) {
                            throw new ForbiddenException(__('No permission to attach this cluster to given target.'));
                        }
                    } else {
                        if (!$this->ACL->canModifyTagCollection($user, $target)) {
                            throw new ForbiddenException(__('No permission to attach this cluster to given target.'));
                        }
                    }
                    $result = $this->Galaxy->attachCluster($user, $target_type, $target, $cluster_id, $local);
                    if ($mirrorOnEventRequested) {
                        $result = $result && $this->Galaxy->attachCluster($user, 'event', $event_id, $cluster_id, $local);
                    }
                }
            }
            if ($this->request->is('ajax')) {
                return new Response(['body' => json_encode(['saved' => true, 'success' => $result, 'check_publish' => true]), 'status' => 200, 'type' => 'json']);
            }

            $this->Flash->info($result);
            $this->redirect($this->referer());
        }

        $this->set('mirrorOnEvent', $mirrorOnEvent);
        $this->set('local', $local);
        $this->set('target_id', $target_id);
        $this->set('target_type', $target_type);
        $this->layout = false;
        $this->autoRender = false;
        $this->render('/Galaxies/ajax/attach_multiple_clusters');
    }

    public function viewGraph($id)
    {
        $cluster = $this->Galaxy->GalaxyCluster->find(
            'first',
            [
                'conditions' => ['GalaxyCluster.id' => $id],
                'contain' => ['Galaxy'],
                'recursive' => -1
            ]
        );
        if (empty($cluster)) {
            throw new MethodNotAllowedException('Invalid Galaxy.');
        }
        $this->set('cluster', $cluster);
        $this->set('defaultCluster', $cluster['GalaxyCluster']['default']);
        $this->set('scope', 'galaxy');
        $this->set('id', $id);
        $this->set('galaxy_id', $cluster['Galaxy']['id']);
        $this->render('/Events/view_graph');
    }

    public function showGalaxies($id, $scope = 'event')
    {
        if ($scope === 'event') {
            $EventsTable = $this->fetchTable('Events');
            $object = $EventsTable->fetchEvent($this->Auth->user(), ['eventid' => $id, 'metadata' => 1]);
            if (empty($object)) {
                throw new NotFoundException('Invalid event.');
            }
            $object = $object[0];
        } elseif ($scope === 'attribute') {
            $AttributesTable = $this->fetchTable('Attributes');
            $object = $AttributesTable->fetchAttributeSimple(
                $this->Auth->user(),
                [
                    'conditions' => ['Attribute.id' => $id],
                    'contain' => [
                        'Event',
                        'Object',
                        'AttributeTag' => [
                            'fields' => ['AttributeTag.id', 'AttributeTag.tag_id', 'AttributeTag.relationship_type', 'AttributeTag.local'],
                            'Tag' => ['fields' => ['Tag.id', 'Tag.name', 'Tag.colour', 'Tag.exportable']],
                        ],
                    ],
                ]
            );
            if (empty($object)) {
                throw new NotFoundException('Invalid attribute.');
            }
            $object = $AttributesTable->Event->massageTags($this->Auth->user(), $object, 'Attribute');
        } elseif ($scope === 'tag_collection') {
            $TagsCollectionTable = $this->fetchTable('TagCollections');
            $object = $TagsCollectionTable->fetchTagCollection($this->Auth->user(), ['conditions' => ['TagCollection.id' => $id]]);
            if (empty($object)) {
                throw new NotFoundException('Invalid Tag Collection.');
            }
            $object = $object[0];
        } else {
            throw new NotFoundException("Invalid scope.");
        }

        $this->layout = false;
        $this->set('scope', $scope);
        $this->set('object', $object);
        $this->render('/Events/ajax/ajaxGalaxies');
    }

    public function forkTree($galaxyId, $pruneRootLeaves = true)
    {
        $clusters = $this->Galaxy->GalaxyCluster->fetchGalaxyClusters($this->Auth->user(), ['conditions' => ['GalaxyCluster.galaxy_id' => $galaxyId]], $full = true);
        if (empty($clusters)) {
            throw new MethodNotAllowedException('Invalid Galaxy.');
        }
        $this->Galaxy->GalaxyCluster->attachExtendByInfo($this->Auth->user(), $clusters);
        foreach ($clusters as $k => $cluster) {
            $clusters[$k] = $this->Galaxy->GalaxyCluster->attachExtendFromInfo($this->Auth->user(), $clusters[$k]);
        }
        $galaxy = $this->Galaxy->find(
            'first',
            [
                'recursive' => -1,
                'conditions' => ['Galaxy.id' => $galaxyId]
            ]
        );
        $tree = $this->Galaxy->generateForkTree($clusters, $galaxy, $pruneRootLeaves = $pruneRootLeaves);
        if ($this->ParamHandler->isRest()) {
            return $this->RestResponse->viewData($tree, $this->response->getType());
        }
        $this->set('tree', $tree);
        $this->set('galaxy', $galaxy);
        $this->set('galaxy_id', $galaxyId);
    }

    public function relationsGraph($galaxyId, $includeInbound = 0)
    {
        $clusters = $this->Galaxy->GalaxyCluster->fetchGalaxyClusters($this->Auth->user(), ['conditions' => ['GalaxyCluster.galaxy_id' => $galaxyId]], $full = true);
        if (empty($clusters)) {
            throw new MethodNotAllowedException('Invalid Galaxy.');
        }
        $galaxy = $this->Galaxy->find(
            'first',
            [
                'recursive' => -1,
                'conditions' => ['Galaxy.id' => $galaxyId]
            ]
        );
        $grapher = new ClusterRelationsGraphTool($this->Auth->user(), $this->Galaxy->GalaxyCluster);
        $relations = $grapher->getNetwork($clusters, $includeInbound, $includeInbound);
        if ($this->ParamHandler->isRest()) {
            return $this->RestResponse->viewData($relations, $this->response->getType());
        }
        $this->set('relations', $relations);
        $this->set('galaxy', $galaxy);
        $this->set('galaxy_id', $galaxyId);
        $this->set('includeInbound', $includeInbound);
        $AttributeTable = $this->fetchTable('Attributes');
        $distributionLevels = $AttributeTable->distributionLevels;
        $this->set('distributionLevels', $distributionLevels);
    }
}
