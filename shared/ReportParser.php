<?php
declare(strict_types=1);

/**
 * Parser for the security review report.md file
 * Extracts issues and organizes them by section
 */
class ReportParser
{
    private string $reportPath;

    public function __construct(string $reportPath = null)
    {
        $this->reportPath = $reportPath ?? __DIR__ . '/../report.md';
    }

    /**
     * Parse the entire report and return structured data
     */
    public function parseReport(): array
    {
        if (!file_exists($this->reportPath)) {
            throw new Exception('Report file not found: ' . $this->reportPath);
        }

        $content = file_get_contents($this->reportPath);
        $lines = explode("\n", $content);

        $sections = [];
        $currentSection = null;
        $currentFile = null;

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);

            // Detect main section headers (e.g., "# Admin Section" or "# Root Level")
            if (preg_match('/^#\s+(Root Level|Admin Section|Donor Section|Registrar Section|Public Section|API Section|Reports Section)$/', $line, $matches)) {
                $currentSection = $this->normalizeSectionName($matches[1]);
                $sections[$currentSection] = [
                    'name' => $matches[1],
                    'files' => []
                ];
                $currentFile = null;
                continue;
            }

            // Detect file headers (e.g., "## admin/index.php") — allow any extension
            if ($currentSection && preg_match('/^##\s+(.+)$/', $line, $matches)) {
                $filePath = $matches[1];
                $currentFile = $filePath;
                $sections[$currentSection]['files'][$filePath] = [
                    'path' => $filePath,
                    'issues' => [],
                    'enhancements' => []
                ];
                continue;
            }

            // Detect Critical Issues section
            if ($line === '### Critical Issues' && $currentFile && $currentSection) {
                $issues = $this->extractIssues($lines, $lineNumber + 1, 'critical');
                $sections[$currentSection]['files'][$currentFile]['issues'] = $issues;
                continue;
            }

            // Detect Enhancements section
            if ($line === '### Enhancements' && $currentFile && $currentSection) {
                $enhancements = $this->extractIssues($lines, $lineNumber + 1, 'enhancement');
                $sections[$currentSection]['files'][$currentFile]['enhancements'] = $enhancements;
                continue;
            }
        }

        return $sections;
    }

    /**
     * Extract issues from a section (Critical Issues or Enhancements)
     */
    private function extractIssues(array $lines, int $startLine, string $type): array
    {
        $issues = [];
        $currentIssue = null;

        for ($i = $startLine; $i < count($lines); $i++) {
            $line = trim($lines[$i]);

            // Stop at next section header or file header or separator
            if (preg_match('/^#{2,3}\s+/', $line) || $line === '---') {
                break;
            }

            // Skip empty lines quietly
            if ($line === '') {
                continue;
            }

            // If section explicitly says NONE, exit with empty list
            if (stripos($line, 'none') === 0 || preg_match('/^-\s+\*\*NONE\*\*/i', $line)) {
                return [];
            }

            // Detect numbered list items (1. Issue description)
            if (preg_match('/^(\d+)\.\s+(.+)$/', $line, $matches)) {
                if ($currentIssue) {
                    $issues[] = $currentIssue;
                }

                $currentIssue = [
                    'number' => (int)$matches[1],
                    'title' => $matches[2],
                    'description' => $matches[2],
                    'type' => $type,
                    'priority' => $this->determinePriority($matches[2], $type)
                ];
                continue;
            }

            // Detect bullet list items as new issues when no numbering
            if (preg_match('/^[-*+]\s+(.+)$/', $line, $matches)) {
                $text = $matches[1];
                // If we are already in an issue, treat bullet as sub-detail
                if ($currentIssue) {
                    $currentIssue['description'] .= "\n• " . $text;
                } else {
                    $currentIssue = [
                        'number' => null,
                        'title' => $text,
                        'description' => $text,
                        'type' => $type,
                        'priority' => $this->determinePriority($text, $type)
                    ];
                }
                continue;
            }

            // Continue accumulating description for current issue
            if ($currentIssue) {
                $currentIssue['description'] .= ' ' . $line;
            }
        }

        // Add the last issue if exists
        if ($currentIssue) {
            $issues[] = $currentIssue;
        }

        return $issues;
    }

    /**
     * Normalize section names for consistency
     */
    private function normalizeSectionName(string $name): string
    {
        $mapping = [
            'Root Level' => 'root',
            'Admin Section' => 'admin',
            'Donor Section' => 'donor',
            'Registrar Section' => 'registrar',
            'Public Section' => 'public',
            'API Section' => 'api',
            'Reports Section' => 'reports'
        ];

        return $mapping[$name] ?? strtolower(str_replace(' ', '_', $name));
    }

    /**
     * Determine priority based on issue description and type
     */
    private function determinePriority(string $description, string $type): string
    {
        $lowercaseDesc = strtolower($description);

        // Critical issues are always high priority
        if ($type === 'critical') {
            return 'critical';
        }

        // High priority enhancements
        if (strpos($lowercaseDesc, 'security') !== false ||
            strpos($lowercaseDesc, 'vulnerability') !== false ||
            strpos($lowercaseDesc, 'attack') !== false ||
            strpos($lowercaseDesc, 'brute force') !== false) {
            return 'high';
        }

        // Medium priority enhancements
        if (strpos($lowercaseDesc, 'validation') !== false ||
            strpos($lowercaseDesc, 'rate limiting') !== false ||
            strpos($lowercaseDesc, 'logging') !== false ||
            strpos($lowercaseDesc, 'error handling') !== false) {
            return 'medium';
        }

        // Default to low
        return 'low';
    }

    /**
     * Convert parsed data to database insert format
     */
    public function getDatabaseInserts(): array
    {
        $sections = $this->parseReport();
        $inserts = [];

        foreach ($sections as $sectionKey => $section) {
            foreach ($section['files'] as $filePath => $file) {
                // Add critical issues
                foreach ($file['issues'] as $issue) {
                    $inserts[] = [
                        'section' => $section['name'],
                        'file_path' => $filePath,
                        'issue_type' => 'critical',
                        'title' => $issue['title'],
                        'description' => $issue['description'],
                        'priority' => $issue['priority'],
                        'status' => 'pending'
                    ];
                }

                // Add enhancements
                foreach ($file['enhancements'] as $enhancement) {
                    $inserts[] = [
                        'section' => $section['name'],
                        'file_path' => $filePath,
                        'issue_type' => 'enhancement',
                        'title' => $enhancement['title'],
                        'description' => $enhancement['description'],
                        'priority' => $enhancement['priority'],
                        'status' => 'pending'
                    ];
                }
            }
        }

        return $inserts;
    }
}