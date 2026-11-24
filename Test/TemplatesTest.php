<?php

use KanboardTests\units\Base;

require_once __DIR__.'/BroadsideHarness.php';

class TemplatesTest extends Base
{
    use BroadsideHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container['route']->enable();
        $this->setUpBroadside();
    }

    public function testProjectMenusOnlyShowArtifactsToUsersWithProjectAccess(): void
    {
        $project = $this->container['projectModel']->getById($this->project);
        $hook = $this->container['helper']->hook;

        foreach (['viewer', 'outsider'] as $user) {
            $_SESSION['user'] = $this->container['userModel']->getById($this->users[$user]);
            foreach (['template:project:dropdown', 'template:project-header:view-switcher', 'template:project:sidebar'] as $name) {
                $html = $hook->render($name, ['project' => $project, 'filters' => []]);
                if ($user === 'viewer') {
                    $this->assertStringContainsString('Artifacts', $html, $name);
                    $this->assertStringContainsString('href="broadside/project/'.$this->project.'"', $html, $name);
                } else {
                    $this->assertSame('', $html, $name);
                }
            }
        }
    }

    public function testTaskSidebarListsVisibleLinkedArtifacts(): void
    {
        $taskId = (int) $this->container['taskCreationModel']->create([
            'title' => 'Linked task',
            'project_id' => $this->project,
        ]);
        $model = $this->container['broadsideArtifactModel'];
        $data = ['type' => 'html', 'body' => '<p>Report</p>'];
        $linked = $model->publish($this->project, $this->users['member'], $data + ['title' => 'Linked & report', 'task_id' => $taskId]);
        $removed = $model->publish($this->project, $this->users['member'], $data + ['title' => 'Removed report', 'task_id' => $taskId]);
        $model->remove($removed['artifact_id']);
        $model->publish($this->project, $this->users['member'], $data + ['title' => 'Unlinked report']);

        $task = $this->container['taskFinderModel']->getById($taskId);
        $hook = $this->container['helper']->hook;
        $_SESSION['user'] = $this->container['userModel']->getById($this->users['viewer']);
        $html = $hook->render('template:task:sidebar:information', ['task' => $task]);
        $this->assertStringContainsString('Linked &amp; report', $html);
        $this->assertStringContainsString('href="broadside/artifact/'.$linked['artifact_id'].'"', $html);
        $this->assertStringNotContainsString('Removed report', $html);
        $this->assertStringNotContainsString('Unlinked report', $html);

        $_SESSION['user'] = $this->container['userModel']->getById($this->users['outsider']);
        $this->assertSame('', $hook->render('template:task:sidebar:information', ['task' => $task]));
    }
}
