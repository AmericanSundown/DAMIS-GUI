<?php

namespace Damis\ExperimentBundle\Controller;

use Base\ConvertBundle\Helpers\ReadFile;
use Damis\DatasetsBundle\Entity\Dataset;
use Guzzle\Http\Client;
use PHPExcel_IOFactory;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Damis\ExperimentBundle\Entity\Experiment;
use Damis\EntitiesBundle\Entity\Workflowtask;
use Damis\EntitiesBundle\Entity\Parametervalue;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Damis\EntitiesBundle\Entity\Pvalueoutpvaluein;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

class ExperimentController extends Controller
{
    /**
     * New experiment workflow creation window
     *
     * @Route("/experiment/new.html", name="new_experiment")
     * @Template()
     */
    public function newAction()
    {
        $clusters = $this->getDoctrine()
            ->getManager()
            ->getRepository('DamisExperimentBundle:Cluster')
            ->findAll();

        $componentsCategories = $this->getDoctrine()
            ->getManager()
            ->getRepository('DamisExperimentBundle:ComponentType')
            ->findAll();

        $components = $this->getDoctrine()
            ->getManager()
            ->getRepository('DamisExperimentBundle:Component')
            ->findAll();

        /** @var $experimentRepository \Damis\ExperimentBundle\Entity\ExperimentRepository */
        $experimentRepository = $this->getDoctrine()
            ->getManager()
            ->getRepository('DamisExperimentBundle:Experiment');

        $nextName = $experimentRepository->getNextExperimentNameNumber();


        return [
            'clusters' => $clusters,
            'componentsCategories' => $componentsCategories,
            'components' => $components,
            'workFlowState' => null,
            'taskBoxesCount' => 0,
            'experimentId' => null,
            'experimentTitle' => 'exp' . $nextName
        ];
    }

    /**
     * Edit experiment in workflow creation window
     *
     * @Route("/experiment/{id}/edit.html", name="edit_experiment")
     * @Template("DamisExperimentBundle:Experiment:new.html.twig")
     */
    public function editAction($id)
    {
        $data = $this->newAction();

        /** @var $experiment Experiment */
        $experiment = $this->getDoctrine()
            ->getManager()
            ->getRepository('DamisExperimentBundle:Experiment')
            ->findOneById($id);

        $data['workFlowState'] = $experiment->getGuiData();
        $data['taskBoxesCount'] = @explode('***', $data['workFlowState'])[2];
        $data['experimentId'] = $id;
        $data['experimentTitle'] = $experiment->getName();

        return $data;
    }

    /**
     * Experiment save
     *
     * @Route("/experiment/save.html", name="experiment_save")
     * @Method("POST")
     * @Template()
     */
    public function saveAction(Request $request)
    {
        $params = $request->request->all();
        $isValid = isset($params['valid_form']);
        if($isValid)
            $isValid = $params['valid_form'] == 1 ? true : false;
        $isChanged = isset($params['workflow_changed']);
        if($isChanged)
            $isChanged = $params['workflow_changed'] == 1 ? true : false;

        /* @var $experiment Experiment */
        if($params['experiment-id'])
            $experiment = $this->getDoctrine()
                ->getRepository('DamisExperimentBundle:Experiment')
                ->findOneBy(['id' => $params['experiment-id']]);
        else
            $experiment = false;

        $isNew = !(boolean)$experiment;
        if ($isNew)
            $experiment = new Experiment();

        $experiment->setName($params['experiment-title']);
        $experiment->setGuiData($params['experiment-workflow_state']);
        $isExecution = isset($params['experiment-execute']);

        if($isExecution)
            $isExecution = ($params['experiment-execute'] > 0);

        if($isExecution) {
            $experiment->setMaxDuration(new \DateTime($params['experiment-max_calc_time']));
            $experiment->setUseCpu($params['experiment-p']);
        }

        $experiment->setUser($this->get('security.context')->getToken()->getUser());

        $em = $this->getDoctrine()->getManager();
        $oldStatus = false;
        if(!$isNew)
            $oldStatus = $experiment->getStatus();

        if($isExecution && $isChanged && $isValid && !$isNew)
            $experimentStatus = $em->getRepository('DamisExperimentBundle:Experimentstatus')
                ->findOneByExperimentstatusid(2);
        elseif(!$isExecution && $isChanged && $isValid && $isNew)
            $experimentStatus = $em->getRepository('DamisExperimentBundle:Experimentstatus')
                ->findOneByExperimentstatusid(1);
        elseif($isExecution && $isChanged && $isValid && $isNew)
            $experimentStatus = $em->getRepository('DamisExperimentBundle:Experimentstatus')
                ->findOneByExperimentstatusid(2);
        elseif(!$isExecution && $isChanged && $isValid && !$isNew)
            $experimentStatus = $em->getRepository('DamisExperimentBundle:Experimentstatus')
                ->findOneByExperimentstatusid(1);
        elseif($isExecution && !$isChanged && $isValid)
            $experimentStatus = $em->getRepository('DamisExperimentBundle:Experimentstatus')
                ->findOneByExperimentstatusid(2);
        elseif(!$isExecution && !$isChanged && $isValid && !$isNew)
            $experimentStatus = $oldStatus;
        elseif($isChanged && !$isValid)
            $experimentStatus = $em->getRepository('DamisExperimentBundle:Experimentstatus')
                ->findOneByExperimentstatusid(1);
        elseif(!$isChanged && !$isValid)
            $experimentStatus = $em->getRepository('DamisExperimentBundle:Experimentstatus')
                ->findOneByExperimentstatusid(1);
        else
            $experimentStatus = $em->getRepository('DamisExperimentBundle:Experimentstatus')
                ->findOneByExperimentstatusid(2);


        if($experimentStatus)
            $experiment->setStatus($experimentStatus);
        $em->persist($experiment);
        $em->flush();

        if($isExecution)
            $this->populate($experiment->getId());
        if($isValid){
            $this->get('session')->getFlashBag()->add('success', 'Experiment successfully created!');
        }
        if($isValid)
            return ['experiment' => $experiment];
        else
            return new Response($experiment->getId());
    }

    /**
     * Experiment execution
     *
     * @Route("/experiment/{id}/execute.html", name="execute_experiment")
     * @Template()
     */
    public function executeAction($id){
        $em = $this->getDoctrine()->getManager();

        /* @var $experiment \Damis\ExperimentBundle\Entity\Experiment */
        $experiment = $em
            ->getRepository('DamisExperimentBundle:Experiment')
            ->findOneBy(['id' => $id]);

        if (!$experiment) {
            throw $this->createNotFoundException('Unable to find Experiment entity.');
        }

        $this->populate($id);

        $experimentStatus = $em
            ->getRepository('DamisExperimentBundle:Experimentstatus')
            ->findOneBy(['experimentstatus' => 'EXECUTING']);

        $experiment->setStatus($experimentStatus);
        $em->flush();

        return $this->redirect($this->get('request')->headers->get('referer'));
    }

    public function populate($id){
        $em = $this->getDoctrine()->getManager();

        /* @var $experiment \Damis\ExperimentBundle\Entity\Experiment */
        $experiment = $em
            ->getRepository('DamisExperimentBundle:Experiment')
            ->findOneBy(['id' => $id]);

        if (!$experiment) {
            throw $this->createNotFoundException('Unable to find Experiment entity.');
        }

        $guiDataExploded = explode('***', $experiment->getGuiData());
        $workflows = json_decode($guiDataExploded[0]);
        $workflowsConnections = json_decode($guiDataExploded[1]);

        //remove workflotasks at first, this should remove parametervalues and parametervaluein-out too
        foreach($experiment->getWorkflowtasks() as $task){
            $em->remove($task);
        }
        $em->flush();

        $workflowsSaved = array();

        foreach($workflows as $workflow){
            /* @var $component \Damis\ExperimentBundle\Entity\Component */
            $component = $em
                ->getRepository('DamisExperimentBundle:Component')
                ->findOneBy(['id' => $workflow->componentId]);

            if (!$component) {
                continue;
            }

            //New workflowtask
            $workflowTask = new Workflowtask();
            $workflowTask->setExperiment($experiment);
            $workflowTask->setWorkflowtaskisrunning(false);
            $workflowTask->setTaskBox($workflow->boxId);
            $em->persist($workflowTask);
            $em->flush();

            $wf = array();

            /* @var $parameter \Damis\ExperimentBundle\Entity\Parameter */
            foreach($component->getParameters() as $parameter){
                $value = new Parametervalue();
                $value->setWorkflowtask($workflowTask);
                $value->setParameter($parameter);
                $value->setParametervalue(null);

                foreach($workflow->form_parameters as $form){
                    if ($form){
                        if (!isset($form->id) or !isset($form->value)){
                            continue;
                        }
                        if ($form->id == $parameter->getId())
                            if(is_array($form->value))
                                $value->setParametervalue(json_encode($form->value));
                            else
                                $value->setParametervalue($form->value);
                    }
                }
                if(isset(json_decode($value->getParametervalue(), true)['path']) && strpos(json_decode($value->getParametervalue(), true)['path'] , '/') !== FALSE){
                    $data = json_decode($value->getParametervalue(), true);
                    $client = new Client($this->container->getParameter('midas_url'));
                    $session = $this->get('request')->getSession();
                    if($session->has('sessionToken'))
                        $sessionToken = $session->get('sessionToken');
                    else {
                        $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('Error fetching file', array(), 'DatasetsBundle'));
                        return false;
                    }
                    //$sessionToken = 'b3k96m3jqonfmc3ilemo4db0oh';
                    $req = $client->get('/web/action/file-explorer/file?path='.$data['path'].'&name='.$data['name'].'&repositoryType=research&type=FILE&authorization='.$sessionToken);
                    try {
                        $body = $req->send()->getBody(true);
                        $file = new Dataset();
                        $file->setDatasetTitle(basename($data['name']));
                        $file->setDatasetCreated(time());
                        $user = $this->get('security.context')->getToken()->getUser();
                        $file->setUserId($user);
                        $file->setDatasetIsMidas(true);
                        $temp_file = $this->container->getParameter("kernel.cache_dir") . '/../'. time() . $data['name'];
                        $em->persist($file);
                        $em->flush();
                        $fp = fopen($temp_file,"w");
                        fwrite($fp, $body);
                        fclose($fp);

                        $file2 = new File($temp_file);

                        $ref_class = new ReflectionClass('Damis\DatasetsBundle\Entity\Dataset');
                        $mapping = $this->container->get('iphp.filestore.mapping.factory')->getMappingFromField($file, $ref_class, 'file');
                        $file_data = $this->container->get('iphp.filestore.filestorage.file_system')->upload($mapping, $file2);

                        $org_file_name = basename($data['name']);
                        $file_data['originalName'] = $org_file_name;

                        $file->setFile($file_data);
                        $em->persist($file);
                        $em->flush();
                        unlink($temp_file);
                        $value->setParametervalue($file->getDatasetId());
                        $response = $this->uploadArff($file->getDatasetId());
                        if(!$response)
                            return false;
                    } catch (\Guzzle\Http\Exception\BadResponseException $e) {
                        $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('Error fetching file', array(), 'DatasetsBundle'));
                        return false;
                    }

                }
                $em->persist($value);
                $em->flush();

                if ($parameter->getConnectionType()->getId() == '1'){
                    $wf['in'] = $value->getParametervalueid();
                }
                if ($parameter->getConnectionType()->getId() == '2'){
                    $wf['out'][$parameter->getSlug()] = $value->getParametervalueid();
                }

            }

            $wf['id'] = $workflowTask->getWorkflowtaskid();
            $workflowsSaved[$workflow->boxId] = $wf;
        }

        foreach($workflowsConnections as $conn){
            if (isset($workflowsSaved[$conn->sourceBoxId]) and isset($workflowsSaved[$conn->targetBoxId])){
                if ( (isset($workflowsSaved[$conn->sourceBoxId]['out']['Y']) or isset($workflowsSaved[$conn->sourceBoxId]['out']['Yalt'])) and isset($workflowsSaved[$conn->targetBoxId]['in']) ) {
                    //sugalvojom tokia logika:
                    //jei nustaytas sourceAnchor tipas vadinasi tai yra Y connectionas
                    //by default type = Right
                    if (isset($conn->sourceAnchor->type) and ($conn->sourceAnchor->type == "Right")) {
                        /** @var $valOut \Damis\EntitiesBundle\Entity\Parametervalue */
                        $valOut = $em
                            ->getRepository('DamisEntitiesBundle:Parametervalue')
                            ->findOneBy(['parametervalueid' => $workflowsSaved[$conn->sourceBoxId]['out']['Y'] ]);

                        /** @var $valIn \Damis\EntitiesBundle\Entity\Parametervalue */
                        $valIn = $em
                            ->getRepository('DamisEntitiesBundle:Parametervalue')
                            ->findOneBy(['parametervalueid' => $workflowsSaved[$conn->targetBoxId]['in'] ]);
                    } else {
                        /** @var $valOut \Damis\EntitiesBundle\Entity\Parametervalue */
                        $valOut = $em
                            ->getRepository('DamisEntitiesBundle:Parametervalue')
                            ->findOneBy(['parametervalueid' => $workflowsSaved[$conn->sourceBoxId]['out']['Yalt'] ]);

                        /** @var $valIn \Damis\EntitiesBundle\Entity\Parametervalue */
                        $valIn = $em
                            ->getRepository('DamisEntitiesBundle:Parametervalue')
                            ->findOneBy(['parametervalueid' => $workflowsSaved[$conn->targetBoxId]['in'] ]);
                    }

                    $connection = new Pvalueoutpvaluein;
                    $connection->setOutparametervalue($valOut);
                    $connection->setInparametervalue($valIn);
                    $valIn->setParametervalue($valOut->getParametervalue());
                    $em->persist($connection);
                    $em->flush();
                }
            }
        }
    }

    /**
     * Edit experiment in workflow creation window
     *
     * @Route("/experiment/{id}/show.html", name="see_experiment")
     * @Template("DamisExperimentBundle:Experiment:new.html.twig")
     */
    public function seeAction($id)
    {
        $data = $this->newAction();

        /** @var $experiment Experiment */
        $experiment = $this->getDoctrine()
            ->getManager()
            ->getRepository('DamisExperimentBundle:Experiment')
            ->findOneById($id);

        $tasksBoxsWithErrors = [];
        $executedTasksBoxs = [];
        /** @var $task Workflowtask */
        foreach($experiment->getWorkflowtasks() as $task) {
            /** @var $value \Damis\EntitiesBundle\Entity\Parametervalue */
            foreach($task->getParameterValues() as $value)
                if($value->getParameter()->getConnectionType()->getId() == 2)
                    $data['datasets'][$task->getTaskBox()][] = $value->getParametervalue();

            if(in_array($task->getWorkflowtaskisrunning(), [1, 3]))
                $tasksBoxsWithErrors[] = $task->getTaskBox();
            elseif($task->getWorkflowtaskisrunning() == 2)
                $executedTasksBoxs[] = $task->getTaskBox();
        }

        $data['workFlowState'] = $experiment->getGuiData();
        $data['taskBoxesCount'] = explode('***', $data['workFlowState'])[2];
        $data['experimentId'] = $id;
        $data['experimentTitle'] = $experiment->getName();
        $data['tasksBoxsWithErrors'] = $tasksBoxsWithErrors;
        $data['executedTasksBoxs'] = $executedTasksBoxs;

        return $data;
    }

    /**
     * Delete experiments
     *
     * @Route("/delete.html", name="experiment_delete")
     * @Method("POST")
     */
    public function deleteAction(Request $request)
    {
        $experiments = json_decode($request->request->get('experiment-delete-list'));
        $em = $this->getDoctrine()->getManager();
        foreach($experiments as $id){
            $experiment = $em->getRepository('DamisExperimentBundle:Experiment')->findOneById($id);
            if($experiment){
                $files = $em->getRepository('DamisEntitiesBundle:Parametervalue')->getExperimentDatasets($id);
                foreach($files as $fileId){
                    /** @var $file \Damis\DatasetsBundle\Entity\Dataset */
                    $file = $em->getRepository('DamisDatasetsBundle:Dataset')
                        ->findOneBy(array('datasetId' => $fileId, 'hidden' => true));
                    if($file){
                        if(file_exists('.' . $file->getFilePath()))
                            unlink('.' . $file->getFilePath());
                        $em->remove($file);
                    }
                }
                $em->remove($experiment);
                $em->flush();
            }
        }
        return $this->redirect($this->generateUrl('experiments_history'));
    }

    /**
     * When uploading csv/txt/tab/xls/xlsx types to arff
     * convert it and save
     *
     * @param String $id
     * @return boolean
     */
    public function uploadArff($id)
    {
        $memoryLimit = ini_get('memory_limit');
        $suffix = '';
        sscanf ($memoryLimit, '%u%c', $number, $suffix);
        if (isset ($suffix))
        {
            $number = $number * pow (1024, strpos (' KMG', $suffix));
        }
        $user = $this->get('security.context')->getToken()->getUser();
        $em = $this->getDoctrine()->getManager();
        $entity = $em->getRepository('DamisDatasetsBundle:Dataset')
            ->findOneBy(array('userId' => $user, 'datasetId' => $id));
        if($entity){
            $format = explode('.', $entity->getFile()['fileName']);
            $format = $format[count($format)-1];
            $filename = $entity->getDatasetTitle();
            if ($format == 'zip'){
                $zip = new ZipArchive();
                $res = $zip->open('./assets' . $entity->getFile()['fileName']);
                $name = $zip->getNameIndex(0);
                if($zip->numFiles > 1){
                    $em->remove($entity);
                    $em->flush();
                    $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('Dataset has wrong format!', array(), 'DatasetsBundle'));
                    return false;
                }

                if($res === true){
                    $path = substr($entity->getFile()['path'], 0, strripos($entity->getFile()['path'], '/'));
                    $zip->extractTo('.' . $path, $name);
                    $zip->close();
                    $format = explode('.', $name);
                    $format = $format[count($format)-1];
                    $fileReader = new ReadFile();
                    if ($format == 'arff'){
                        $dir = substr($entity->getFile()['path'], 0, strripos($entity->getFile()['path'], '.'));
                        $entity->setFilePath($dir . '.arff');
                        $rows = $fileReader->getRows('.' . $entity->getFilePath() , $format);
                        if($rows === false){
                            $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('Exceeded memory limit!', array(), 'DatasetsBundle'));
                            $em->remove($entity);
                            $em->flush();
                            unlink('.' . $path . '/' . $name);
                            return false;
                        }
                        unset($rows);
                        $em->persist($entity);
                        $em->flush();
                        rename ( '.' . $path . '/' . $name , '.' . $dir . '.arff');
                        $this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('Dataset successfully uploaded!', array(), 'DatasetsBundle'));
                        return true;
                    }
                    elseif($format == 'txt' || $format == 'tab' || $format == 'csv'){
                        $rows = $fileReader->getRows('.' . $path . '/' . $name , $format);
                        if($rows === false){
                            $em->remove($entity);
                            $em->flush();
                            unlink('.' . $path . '/' . $name);
                            $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('Dataset is too large!', array(), 'DatasetsBundle'));
                            return false;
                        }
                        unlink('.' . $path . '/' . $name);
                    } elseif($format == 'xls' || $format == 'xlsx'){
                        $objPHPExcel = PHPExcel_IOFactory::load('.' . $path . '/' . $name);
                        $rows = $objPHPExcel->setActiveSheetIndex(0)->toArray();
                        array_unshift($rows, null);
                        unlink('.' . $path . '/' . $name);
                        unset($rows[0]);
                    } else{
                        $em->remove($entity);
                        $em->flush();
                        unlink('.' . $path . '/' . $name);
                        $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('Dataset has wrong format!', array(), 'DatasetsBundle'));
                        return false;
                    }
                }
            }
            elseif ($format == 'arff'){
                $entity->setFilePath($entity->getFile()['path']);
                if(memory_get_usage(true) + $entity->getFile()['size'] * 5.8 > $number){
                    $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('Exceeded memory limit!', array(), 'DatasetsBundle'));
                    $em->remove($entity);
                    $em->flush();
                    return false;
                }
                unset($rows);
                $fileReader = new ReadFile();
                $rows = $fileReader->getRows('.' . $entity->getFilePath() , $format);
                if($rows === false){
                    $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('Exceeded memory limit!', array(), 'DatasetsBundle'));
                    $em->remove($entity);
                    $em->flush();
                    unlink('.' . $entity->getFile()['fileName']);
                    return false;
                }
                unset($rows);
                $em->persist($entity);
                $em->flush();
                $this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('Dataset successfully uploaded!', array(), 'DatasetsBundle'));
                return true;
            }
            elseif($format == 'txt' || $format == 'tab' || $format == 'csv'){
                $fileReader = new ReadFile();
                if(memory_get_usage(true) + $entity->getFile()['size'] * 5.8 > $number){
                    $em->remove($entity);
                    $em->flush();
                    $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('Dataset is too large!', array(), 'DatasetsBundle'));
                    return false;
                }
                $rows = $fileReader->getRows('./assets' . $entity->getFile()['fileName'] , $format);
            } elseif($format == 'xls' || $format == 'xlsx'){
                $objPHPExcel = PHPExcel_IOFactory::load('./assets' . $entity->getFile()['fileName']);
                $rows = $objPHPExcel->setActiveSheetIndex(0)->toArray();
                array_unshift($rows, null);
                unset($rows[0]);
            } else{
                $this->get('session')->getFlashBag()->add('error', 'Dataset has wrong format!');
                return false;
            }
            $hasHeaders = false;
            if(!empty($rows)){
                foreach($rows[1] as $header){
                    if(!(is_numeric($header))){
                        $hasHeaders = true;
                    }
                }
            }
            $arff = '';
            $arff .= '@relation ' . $filename . PHP_EOL;
            if($hasHeaders){
                foreach($rows[1] as $key => $header){
                    // Remove spaces in header, to fit arff format
                    $header = preg_replace('/\s+/', '_', $header);

                    // Check string is numeric or normal string
                    if (is_numeric($rows[2][$key])) {
                        if(is_int($rows[2][$key] + 0))
                            $arff .= '@attribute ' . $header . ' ' . 'integer' . PHP_EOL;
                        else if(is_float($rows[2][$key] + 0))
                            $arff .= '@attribute ' . $header . ' ' . 'real' . PHP_EOL;
                    } else {
                        $arff .= '@attribute ' . $header . ' ' . 'string' . PHP_EOL;
                    }
                }
            } else {
                foreach($rows[1] as $key => $header){
                    if (is_numeric($rows[2][$key])) {
                        if(is_int($rows[2][$key] + 0))
                            $arff .= '@attribute ' . 'attr' . $key . ' ' . 'integer' . PHP_EOL;
                        else if(is_float($rows[2][$key] + 0))
                            $arff .= '@attribute ' . 'attr' . $key . ' ' . 'real' . PHP_EOL;
                    } else {
                        $arff .= '@attribute ' . 'attr' . $key . ' ' . 'string' . PHP_EOL;
                    }
                }
            }
            $arff .= '@data' . PHP_EOL;
            if($hasHeaders)
                unset($rows[1]);
            foreach($rows as $row){
                foreach($row as $key => $value)
                    if($key > 0)
                        $arff .= ',' . $value;
                    else
                        $arff .= $value;
                $arff .= PHP_EOL;
            }
            $dir = substr($entity->getFile()['path'], 0, strripos($entity->getFile()['path'], '.'));
            $fp = fopen($_SERVER['DOCUMENT_ROOT'] . $dir . ".arff","w+");
            fwrite($fp, $arff);
            fclose($fp);
            $entity->setFilePath($dir . ".arff");
            $em->persist($entity);
            $em->flush();

            $this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('Dataset successfully uploaded!', array(), 'DatasetsBundle'));
            return true;
        }
        $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('Error!', array(), 'DatasetsBundle'));
        return false;
    }
}
