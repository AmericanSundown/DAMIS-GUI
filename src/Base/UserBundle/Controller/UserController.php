<?php

namespace Base\UserBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Base\UserBundle\Entity\User;
use Base\UserBundle\Form\UserType;
use APY\DataGridBundle\Grid\Source\Entity;
use APY\DataGridBundle\Grid\Action\RowAction;
use APY\DataGridBundle\Grid\Column\ActionsColumn;

/**
 * User controller.
 *
 * @Route("/users")
 */
class UserController extends Controller
{

    /**
     * Lists all User entities.
     *
     * @Route("/", name="users")
     */
    public function indexAction()
    {
        $source = new Entity('BaseUserBundle:User');

        /* @var $grid \APY\DataGridBundle\Grid\Grid */
        $grid = $this->get('grid');

        $grid->setSource($source);
        $grid->setLimits(25);
        $grid->setNoResultMessage($this->get('translator')->trans('No data'));

        //custom colums config
        $grid->hideColumns('id');

        /* @var $column \APY\DataGridBundle\Grid\Column\Column */
        $column = $grid->getColumn('name');
        $column->setOperators(array('like'));
        $column->setOperatorsVisible(false);
        $column->setDefaultOperator('like');
        $column->setTitle($this->get('translator')->trans('form.name', array(), 'FOSUserBundle'));

        $column = $grid->getColumn('surname');
        $column->setOperators(array('like'));
        $column->setOperatorsVisible(false);
        $column->setDefaultOperator('like');
        $column->setTitle($this->get('translator')->trans('form.surname', array(), 'FOSUserBundle'));

        $column = $grid->getColumn('username');
        $column->setOperators(array('like'));
        $column->setOperatorsVisible(false);
        $column->setDefaultOperator('like');
        $column->setTitle($this->get('translator')->trans('form.username', array(), 'FOSUserBundle'));

        $column = $grid->getColumn('email');
        $column->setOperators(array('like'));
        $column->setOperatorsVisible(false);
        $column->setDefaultOperator('like');
        $column->setTitle($this->get('translator')->trans('form.email', array(), 'FOSUserBundle'));

        $column = $grid->getColumn('roles');
        $column->setOperators(array('like'));
        $column->setOperatorsVisible(false);
        $column->setDefaultOperator('like');
        $column->setTitle($this->get('translator')->trans('form.role', array(), 'FOSUserBundle'));

        //add actions column
        $rowAction = new RowAction($this->get('translator')->trans('Edit'), 'user_edit');
        $actionsColumn2 = new ActionsColumn('info_column', $this->get('translator')->trans('Actions'), array($rowAction), "<br/>");
        $grid->addColumn($actionsColumn2);

        return $grid->getGridResponse('BaseUserBundle::User\index.html.twig');
    }

    /**
     * Displays a form to edit an existing User entity.
     *
     * @Route("/{id}/edit", name="user_edit")
     * @Method("GET")
     * @Template()
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('BaseUserBundle:User')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find User entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        );
    }

    /**
    * Creates a form to edit a User entity.
    *
    * @param User $entity The entity
    *
    * @return \Symfony\Component\Form\Form The form
    */
    private function createEditForm(User $entity)
    {
        $form = $this->createForm(new UserType(), $entity, array(
            'action' => $this->generateUrl('user_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing User entity.
     *
     * @Route("/{id}", name="user_update")
     * @Method("PUT")
     * @Template("BaseUserBundle:User:edit.html.twig")
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('BaseUserBundle:User')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find User entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('user_edit', array('id' => $id)));
        }

        return array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        );
    }

    /**
     * Deletes a User entity.
     *
     * @Route("/{id}", name="user_delete")
     * @Method("DELETE")
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('BaseUserBundle:User')->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find User entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('user'));
    }

    /**
     * Creates a form to delete a User entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('user_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm()
        ;
    }
}
