using Avalonia.Controls;

namespace TornPropertyManager;

public partial class MainWindow : Window
{
    private readonly SettingsService _settingsService;
    private readonly TornApiService _apiService;
    public MainWindow(SettingsService settingsService, TornApiService apiService)
    {
        InitializeComponent();
         _settingsService = settingsService;
        _apiService = apiService;
    }
}