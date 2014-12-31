<?php

namespace Damis\DatasetsBundle\Controller;

use Base\ConvertBundle\Helpers\ReadFile;
use Damis\DatasetsBundle\Form\Type\DatasetType;
use Damis\DatasetsBundle\Entity\Dataset;
use Guzzle\Http\Client;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Pagerfanta;
use PHPExcel_IOFactory;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use ZipArchive;

/**
 * Datasets controller.
 *
 * @Route("/datasets")
 */
class DatasetsController extends Controller
{
    /**
     * User datasets list window
     *
     * @Route("/list.html", name="datasets_list")
     * @Method({"GET","POST"})
     * @Template()
     */
    public function listAction(Request $request)
    {
        $sort = $request->get('order_by');
        $user = $this->get('security.context')->getToken()->getUser();
        $em = $this->getDoctrine()->getManager();
        if($sort == 'titleASC')
            $entities = $em->getRepository('DamisDatasetsBundle:Dataset')
                ->getUserDatasets($user, array('title' => 'ASC'));
        elseif($sort == 'titleDESC')
            $entities = $em->getRepository('DamisDatasetsBundle:Dataset')
                ->getUserDatasets($user, array('title' => 'DESC'));
        elseif($sort == 'createdASC')
            $entities = $em->getRepository('DamisDatasetsBundle:Dataset')
                ->getUserDatasets($user, array('created' => 'ASC'));
        elseif($sort == 'createdDESC')
            $entities = $em->getRepository('DamisDatasetsBundle:Dataset')
                ->getUserDatasets($user, array('created' => 'DESC'));
        else
            $entities = $em->getRepository('DamisDatasetsBundle:Dataset')->getUserDatasets($user);
        $paginator = $this->get('knp_paginator');
        $pagination = $paginator->paginate(
            $entities, $this->get('request')->query->get('page', 1), 15);
        return array(
            'entities' => $pagination
        );
    }

    /**
     * Delete datasets
     *
     * @Route("/delete.html", name="datasets_delete")
     * @Method("POST")
     * @Template()
     */
    public function deleteAction(Request $request)
    {
        $files = json_decode($request->request->get('file-delete-list'));
        $em = $this->getDoctrine()->getManager();
        foreach($files as $id){
            $file = $em->getRepository('DamisDatasetsBundle:Dataset')->findOneByDatasetId($id);
            if($file){
                $inUse = $em->getRepository('DamisEntitiesBundle:Parametervalue')->checkDatasets($id);
                if(!$inUse){
                    if(file_exists('.' . $file->getFilePath()))
                        if($file->getFilePath())
                            unlink('.' . $file->getFilePath());
                    $em->remove($file);
                } else {
                    $file->setHidden(true);
                    $em->persist($file);
                }
                $em->flush();
            }
        }
        return $this->redirect($this->generateUrl('datasets_list'));
    }

    /**
     * Upload new dataset
     *
     * @Route("/new.html", name="datasets_new")
     * @Method("GET")
     * @Template()
     */
    public function newAction()
    {
        $entity = new Dataset();
        $form = $this->createForm(new DatasetType(), $entity);
        return array(
            'form' => $form->createView()
        );
    }

    /**
     * Upload new dataset from MIDAS
     *
     * @Route("/midasnew.html", name="datasets_midas_new")
     * @Method("GET")
     * @Template()
     */
    public function newMidasAction(Request $request)
    {
        $client = new Client($this->container->getParameter('midas_url'));
        $notLogged = false;
        $session = $request->getSession();
        $sessionToken = '';
        if($session->has('sessionToken'))
            $sessionToken = $session->get('sessionToken');
        else {
            $notLogged = true;
        }
        $page = ($request->get('page')) ? $request->get('page') : 1;
        $path = ($request->get('path')) ? $request->get('path') : '';
        $uuid = ($request->get('uuid')) ? $request->get('uuid') : 'research';
        $id = $request->get('id');

        $data = json_decode($request->get('data'));
        if($request->get('data') && !empty($data) && $request->get('edit') != 1){
            $id = json_decode($request->get('data'))[0]->value;
            $path = json_decode($id, true)['path'];
            $page = json_decode($id, true)['page'];

            $folders = explode('/', $path);
            $count = count($folders);
            $path = '';
            foreach($folders as $key => $p){
                if($key < $count - 1)
                    $path .= $p . '/';
            }
        }
        $post = array(
            //'path' => $path,
            'page' => $page,
            'pageSize' => 10,
            'extensions' => array('txt', 'tab', 'csv', 'xls', 'xlsx', 'arff'),  // can be added also zip
            //'repositoryType' => 'research'
            'uuid' => $uuid
        );
        $files = [];
        
        $req = $client->post('/web/action/research/folders',
            array('Content-Type' => 'application/json;charset=utf-8', 'authorization' => $sessionToken), json_encode($post));
        try {
            $response = $req->send();
            if($response->getStatusCode() == 200)
                $files = json_decode($response->getBody(true), true);
        } catch (\Guzzle\Http\Exception\BadResponseException $e) {
            $req = $client->post('/web/action/authentication/session/' . $sessionToken . '/check', array('Content-Type' => 'application/json;charset=utf-8', 'authorization' => $sessionToken), array($post));
            try {
                $req->send()->getBody(true);
            } catch (\Guzzle\Http\Exception\BadResponseException $e) {
                $notLogged = true;
            }
        }
        if(isset($files['details']))
            $pageCount = $files['details']['pageCount'];
        else
            $pageCount = 0;
        return array(
            'notLogged' => $notLogged,
            'files' => $files,
            'page' => $page,
            'pageCount' => $pageCount,
            'previous' => $page - 1,
            'next' => $page + 1,
            'path' => $path,
            'uuid' => $uuid,
            'selected' => $id
        );
    }
    /**
     * Create new dataset
     *
     * @Route("/create.html", name="datasets_create")
     * @Method("POST")
     * @Template("DamisDatasetsBundle:Datasets:new.html.twig")
     */
    public function createAction(Request $request)
    {
        $entity = new Dataset();
        $form = $this->createForm(new DatasetType(), $entity);
        $form->submit($request);
        $user = $this->get('security.context')->getToken()->getUser();
        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity->setDatasetCreated(time());
            $entity->setUserId($user);
            $entity->setDatasetIsMidas(false);
            $em->persist($entity);
            $em->flush();
            return $this->uploadArff($entity->getDatasetId());
        }

        return array(
            'form' => $form->createView()
        );
    }

    /**
     * Create new midas dataset
     *
     * @Route("/createmidas.html", name="datasets_create_midas")
     * @Method("POST")
     * @Template("DamisDatasetsBundle:Datasets:newMidas.html.twig")
     */
    public function createMidasAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $client = new Client($this->container->getParameter('midas_url'));
        $data = json_decode($request->request->get('dataset_pk'), true);
        if(!$data){
            $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('File is not selected', array(), 'DatasetsBundle'));
               return $this->redirect($this->generateUrl('datasets_midas_new'));
        }
        $session = $request->getSession();
        if($session->has('sessionToken'))
            $sessionToken = $session->get('sessionToken');
        else {
            $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('Error fetching file', array(), 'DatasetsBundle'));
            return $this->redirect($this->generateUrl('datasets_midas_new'));
        }
        //$req = $client->get('/web/action/file-explorer/file?path='.$data['path'].'&name='.$data['name'].'&repositoryType=research&type=FILE&authorization='.$sessionToken);
		//http://test.midas.lt/web/action/file-explorer/file?name=HBK&idCSV=1104&Authorization=3to1oofbek6s9ljo8d038qe96p
		$req = $client->get('/web/action/file-explorer/file?path='.$data['path'].'&name='.$data['name'].'&idCSV='.$data['idCSV'].'&authorization='.$sessionToken);
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
            return $this->uploadArff($file->getDatasetId());

        } catch (\Guzzle\Http\Exception\BadResponseException $e) {
            $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('Error fetching file', array(), 'DatasetsBundle'));
            return $this->redirect($this->generateUrl('datasets_midas_new'));
        }
    }

    /**
     * Edit dataset
     *
     * @Route("/{id}/edit.html", name="datasets_edit")
     * @Method("GET")
     * @Template()
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();
        $entity = $em->getRepository('DamisDatasetsBundle:Dataset')->findOneByDatasetId($id);
        $form = $this->createForm(new DatasetType(), null);
        $form->get('datasetTitle')->setData($entity->getDatasetTitle());
        $form->get('datasetDescription')->setData($entity->getDatasetDescription());
        return array(
            'form' => $form->createView(),
            'id' => $entity->getDatasetId()
        );
    }

    /**
     * Update dataset
     *
     * @Route("/{id}/update.html", name="datasets_update")
     * @Method("POST")
     * @Template("DamisDatasetsBundle:Datasets:edit.html.twig")
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();
        $entity = $em->getRepository('DamisDatasetsBundle:Dataset')->findOneByDatasetId($id);
        $form = $this->createForm(new DatasetType(), null);
        $form->get('datasetTitle')->setData($entity->getDatasetTitle());
        $form->get('datasetDescription')->setData($entity->getDatasetDescription());
        $form->submit($request);
        if ($form->isValid()) {
            $data = $request->get('datasets_newtype');
            $entity->setDatasetUpdated(time());
            $entity->setDatasetTitle($data['datasetTitle']);
            $entity->setDatasetDescription($data['datasetDescription']);
            $em->persist($entity);
            $em->flush();

            $this->get('session')->getFlashBag()->add('success', 'Dataset successfully updated!');
            return $this->redirect($this->generateUrl('datasets_list'));
        }
        return array(
            'form' => $form->createView(),
            'id' => $id
        );
    }


    /**
     * Dataset upload component form
     *
     * @Route("/upload.html", name="dataset_upload")
     * @Template()
     */
    public function uploadAction(Request $request)
    {
        $entity = new Dataset();
        $form = $this->createForm(new DatasetType(), $entity);
        $data = json_decode($request->query->all()['dataset_url']);
        if($request->query->all() && !empty($data)) {
            $datasetId = $data[0]->value;
            $em = $this->getDoctrine()->getManager();
            $dataset = $em->getRepository('DamisDatasetsBundle:Dataset')->findOneByDatasetId($datasetId);
            return [
                'form' => $form->createView(),
                'file' => $dataset
            ];
        }
        return array(
            'form' => $form->createView(),
            'file' => null
        );
    }

    /**
     * Dataset upload handler for component form
     *
     * @Route("/upload_handler.html", name="dataset_upload_handler")
     * @Method("POST")
     * @Template("DamisDatasetsBundle:Datasets:upload.html.twig")
     */
    public function uploadHandlerAction(Request $request)
    {
        $entity = new Dataset();
        $form = $this->createForm(new DatasetType(), $entity);
        $form->submit($request);
        $user = $this->get('security.context')->getToken()->getUser();

        if ($form->isValid()) {
            if($entity->getFile() == null){
                $form->get('file')
                    ->addError(new FormError($this->get('translator')->trans('This value should not be blank.', array(), 'validators')));
            } else{
                $em = $this->getDoctrine()->getManager();
                $entity->setDatasetCreated(time());
                $entity->setUserId($user);
                $entity->setDatasetIsMidas(false);
                $em->persist($entity);
                $em->flush();
                $format = explode('.', $entity->getFile()['fileName']);
                $format = $format[count($format)-1];
                if ($format == 'zip'){
                    $zip = new ZipArchive();
                    $res = $zip->open('./assets' . $entity->getFile()['fileName']);
                    $name = $zip->getNameIndex(0);
                    if($zip->numFiles > 1){
                        $em->remove($entity);
                        $em->flush();
                        $form->get('file')
                            ->addError(new FormError($this->get('translator')->trans('Too many files in zip!', array(), 'DatasetsBundle')));
                        return [
                            'form' => $form->createView(),
                            'file' => null
                        ];
                    }
                    if($res === true){
                        $path = substr($entity->getFile()['path'], 0, strripos($entity->getFile()['path'], '/'));
                        $zip->extractTo('.' . $path, $name);
                        $zip->close();
                        $format = explode('.', $name);
                        $format = $format[count($format)-1];
                        if($format != 'arff' && $format != 'txt' && $format != 'tab' && $format != 'csv' && $format != 'xls' && $format != 'xlsx'){
                            $form->get('file')
                                ->addError(new FormError($this->get('translator')->trans('Dataset has wrong format!', array(), 'DatasetsBundle')));
                            $em->remove($entity);
                            $em->flush();
                            return [
                                'form' => $form->createView(),
                                'file' => nul
                            ];
                        }
                    } else{
                        $form->get('file')
                            ->addError(new FormError($this->get('translator')->trans('Error!', array(), 'DatasetsBundle')));
                        $em->remove($entity);
                        $em->flush();
                        return [
                            'form' => $form->createView(),
                            'file' => null
                        ];
                    }
                }
                $this->uploadArff($entity->getDatasetId());
                return [
                    'form' => $form->createView(),
                    'file' => $entity
                ];
            }
        } else{
            if($entity->getFile() == null)
                $form->get('file')->addError(new FormError($this->get('translator')->trans('This value should not be blank.', array(), 'validators')));
        }
        return [
            'form' => $form->createView(),
            'file' => null
        ];
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
                    return $this->redirect($this->generateUrl('datasets_new'));
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
                            return $this->redirect($this->generateUrl('datasets_list'));
                        }
                        unset($rows);
                        $em->persist($entity);
                        $em->flush();
                        rename ( '.' . $path . '/' . $name , '.' . $dir . '.arff');
                        $this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('Dataset successfully uploaded!', array(), 'DatasetsBundle'));
                        return $this->redirect($this->generateUrl('datasets_list'));
                    }
                    elseif($format == 'txt' || $format == 'tab' || $format == 'csv'){
                        $rows = $fileReader->getRows('.' . $path . '/' . $name , $format);
                        if($rows === false){
                            $em->remove($entity);
                            $em->flush();
                            unlink('.' . $path . '/' . $name);
                            $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('Dataset is too large!', array(), 'DatasetsBundle'));
                            return $this->redirect($this->generateUrl('datasets_list'));
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
                        return $this->redirect($this->generateUrl('datasets_new'));
                    }
                }
            }
            elseif ($format == 'arff'){
                $entity->setFilePath($entity->getFile()['path']);
                if(memory_get_usage(true) + $entity->getFile()['size'] * 5.8 > $number){
                    $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('Exceeded memory limit!', array(), 'DatasetsBundle'));
                    $em->remove($entity);
                    $em->flush();
                    return $this->redirect($this->generateUrl('datasets_list'));
                }
                unset($rows);
                $fileReader = new ReadFile();
                $rows = $fileReader->getRows('.' . $entity->getFilePath() , $format);
                if($rows === false){
                    $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('Exceeded memory limit!', array(), 'DatasetsBundle'));
                    $em->remove($entity);
                    $em->flush();
                    unlink('.' . $entity->getFile()['fileName']);
                    return $this->redirect($this->generateUrl('datasets_list'));
                }
                unset($rows);
                $em->persist($entity);
                $em->flush();
                $this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('Dataset successfully uploaded!', array(), 'DatasetsBundle'));
                return $this->redirect($this->generateUrl('datasets_list'));
            }
            elseif($format == 'txt' || $format == 'tab' || $format == 'csv'){
                $fileReader = new ReadFile();
                if(memory_get_usage(true) + $entity->getFile()['size'] * 5.8 > $number){
                    $em->remove($entity);
                    $em->flush();
                    $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('Dataset is too large!', array(), 'DatasetsBundle'));
                    return $this->redirect($this->generateUrl('datasets_list'));
                }
                $rows = $fileReader->getRows('./assets' . $entity->getFile()['fileName'] , $format);
            } elseif($format == 'xls' || $format == 'xlsx'){
                $objPHPExcel = PHPExcel_IOFactory::load('./assets' . $entity->getFile()['fileName']);
                $rows = $objPHPExcel->setActiveSheetIndex(0)->toArray();
                array_unshift($rows, null);
                unset($rows[0]);
            } else{
                $this->get('session')->getFlashBag()->add('error', 'Dataset has wrong format!');
                return $this->redirect($this->generateUrl('datasets_list'));
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
            return $this->redirect($this->generateUrl('datasets_list'));
        }
        $this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('Error!', array(), 'DatasetsBundle'));
        return $this->redirect($this->generateUrl('datasets_new'));
    }
}
