<?php

namespace Kanboard\Plugin\Broadside\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\Broadside\Model\Access;
use Kanboard\Plugin\Broadside\Model\Renderer;

class RawController extends BaseController
{
    public function show(): void
    {
        $artifactId = $this->request->getIntegerParam('artifact_id');
        $version = $this->request->getIntegerParam('version');
        $artifact = $this->db->table('broadside_artifacts')
            ->eq('id', $artifactId)
            ->eq('is_removed', 0)
            ->findOne();

        $this->response->withHeader('Cache-Control', 'private, no-store');
        $access = new Access($this->container);
        if (empty($artifact) || ! $access->can((int) $this->userSession->getId(), (int) $artifact['project_id'], 'view')) {
            $this->response->status(404);
            return;
        }

        $this->renderArtifact($artifact, $version);
    }

    protected function renderArtifact(array $artifact, int $version, bool $readOnly = false): void
    {
        $body = $this->container['broadsideArtifactModel']->getVersion((int) $artifact['id'], $version);
        if ($body === null) {
            $this->response->status(404);
            return;
        }

        $html = (new Renderer($this->container))->render(
            $body,
            $artifact['type'],
            $this->request->getStringParam('theme', $this->userSession->getTheme()),
            $this->request->getStringParam('preset', $this->configModel->get('harbor_preset', 'newburgh')),
            $readOnly
        );

        $this->response
            ->withContentSecurityPolicy(Renderer::CSP)
            ->withSecurityHeaders()
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->html($html);
    }
}
