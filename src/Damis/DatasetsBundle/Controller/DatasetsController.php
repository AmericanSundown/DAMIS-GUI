<?php

namespace Damis\DatasetsBundle\Controller;

use Base\ConvertBundle\Helpers\ReadFile;
use Damis\DatasetsBundle\Form\Type\DatasetType;
use Damis\DatasetsBundle\Entity\Dataset;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Pagerfanta;
use PHPExcel_IOFactory;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;

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
            $this->uploadArff($entity->getDatasetId());
            $this->get('session')->getFlashBag()->add('success', 'Dataset successfully uploaded!');
            return $this->redirect($this->generateUrl('datasets_list'));
        }

        return array(
            'form' => $form->createView()
        );
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
            'form' => $form->createView()
        );
    }


    /**
     * Dataset upload component form
     *
     * @Route("/upload.html", name="dataset_upload")
     * @Template()
     */
    public function uploadAction()
    {
        $entity = new Dataset();
        $form = $this->createForm(new DatasetType(), $entity);
        return array(
            'form' => $form->createView()
        );
    }

    /**
     * Dataset upload handler for component form
     *
     * @Route("/upload_handler.html", name="dataset_upload_handler")
     * @Method("POST")
     * @Template()
     */
    public function uploadHandlerAction(Request $request)
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
            $this->uploadArff($entity->getDatasetId());
            return [
                'form' => $form->createView(),
                'file' => $entity
            ];
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
        $user = $this->get('security.context')->getToken()->getUser();
        $em = $this->getDoctrine()->getManager();
        $entity = $em->getRepository('DamisDatasetsBundle:Dataset')
            ->findOneBy(array('userId' => $user, 'datasetId' => $id));
        if($entity){
            $format = explode('.', $entity->getFile()['fileName']);
            $format = $format[count($format)-1];
            $filename = $entity->getDatasetTitle();
            if ($format == 'arff'){
                $entity->setFilePath($entity->getFile()['path']);
                $em->persist($entity);
                $em->flush();
                return true;
            }
            elseif($format == 'txt' || $format == 'tab' || $format == 'csv'){
                $fileReader = new ReadFile();
                $rows = $fileReader->getRows($this->get('kernel')->getRootDir()
                    . '/../web/assets' . $entity->getFile()['fileName'] , $format);
            } elseif($format == 'xls' || $format == 'xlsx'){
                $objPHPExcel = PHPExcel_IOFactory::load($this->get('kernel')->getRootDir()
                    . '/../web/assets' . $entity->getFile()['fileName']);
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
                    if(is_int($rows[2][$key] + 0))
                        $arff .= '@attribute ' . $header . ' ' . 'integer' . PHP_EOL;
                    else if(is_float($rows[2][$key] + 0))
                        $arff .= '@attribute ' . $header . ' ' . 'real' . PHP_EOL;

                }
            } else {
                foreach($rows[1] as $key => $header){
                    if(is_int($rows[2][$key] + 0))
                        $arff .= '@attribute ' . 'attr' . $key . ' ' . 'integer' . PHP_EOL;
                    else if(is_float($rows[2][$key] + 0))
                        $arff .= '@attribute ' . 'attr' . $key . ' ' . 'real' . PHP_EOL;

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
            return true;
        }
        return false;
    }
}
